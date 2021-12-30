<?php

declare(strict_types=1);

namespace App\Radio\AutoDJ;

use App\Entity;
use App\Event\Radio\BuildQueue;
use App\LockFactory;
use App\Message;
use App\Radio\PlaylistParser;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LogLevel;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Queue implements EventSubscriberInterface
{
    protected const TYPES_TO_PLAY_BY_PRIORITY = [
        Entity\StationPlaylist::TYPE_ONCE_PER_HOUR . '_scheduled',
        Entity\StationPlaylist::TYPE_ONCE_PER_HOUR . '_unscheduled',
        Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS . '_scheduled',
        Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS . '_unscheduled',
        Entity\StationPlaylist::TYPE_ONCE_PER_X_MINUTES . '_scheduled',
        Entity\StationPlaylist::TYPE_ONCE_PER_X_MINUTES . '_unscheduled',
        Entity\StationPlaylist::TYPE_DEFAULT . '_scheduled',
        Entity\StationPlaylist::TYPE_DEFAULT . '_unscheduled',
    ];

    public function __construct(
        protected EntityManagerInterface $em,
        protected Logger $logger,
        protected Scheduler $scheduler,
        protected CacheInterface $cache,
        protected LockFactory $lockFactory,
        protected EventDispatcherInterface $dispatcher,
        protected Entity\Repository\StationPlaylistMediaRepository $spmRepo,
        protected Entity\Repository\StationRequestRepository $requestRepo,
        protected Entity\Repository\StationQueueRepository $queueRepo,
        protected Entity\Repository\SongHistoryRepository $historyRepo
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            BuildQueue::class => [
                ['getNextSongFromRequests', 5],
                ['calculateNextSong', 0],
            ],
        ];
    }

    /**
     * Handle event dispatch.
     *
     * @param Message\AbstractMessage $message
     */
    public function __invoke(Message\AbstractMessage $message): void
    {
        if ($message instanceof Message\BuildStationQueue) {
            $station = $this->em->find(Entity\Station::class, $message->station_id);

            if ($station instanceof Entity\Station) {
                $this->buildQueue(
                    station: $station,
                    force: $message->force
                );
            }
        }
    }

    public function buildQueue(
        Entity\Station $station,
        bool $force = false
    ): void {
        $lock = $this->lockFactory->createAndAcquireLock(
            resource: 'autodj_queue_' . $station->getId(),
            ttl: 60,
            force: $force
        );

        if (false === $lock) {
            return;
        }

        $this->logger->pushProcessor(
            function ($record) use ($station) {
                $record['extra']['station'] = [
                    'id' => $station->getId(),
                    'name' => $station->getName(),
                ];
                return $record;
            }
        );

        try {
            // Early-fail if the station is disabled.
            if (!$station->getIsEnabled()) {
                $this->logger->notice('Cannot build queue: station broadcasting is disabled.');
                return;
            }

            // Adjust "expectedCueTime" time from current queue.
            $tzObject = $station->getTimezoneObject();
            $expectedCueTime = CarbonImmutable::now($tzObject);

            // Get expected play time of each item.
            $currentSong = $this->historyRepo->getCurrent($station);
            if (null !== $currentSong) {
                $expectedPlayTime = $this->addDurationToTime(
                    $station,
                    CarbonImmutable::createFromTimestamp($currentSong->getTimestampStart(), $tzObject),
                    $currentSong->getDuration()
                );

                if ($expectedPlayTime < $expectedCueTime) {
                    $expectedPlayTime = $expectedCueTime;
                }
            } else {
                $expectedPlayTime = $expectedCueTime;
            }

            $maxQueueLength = $station->getBackendConfig()->getAutoDjQueueLength();
            if ($maxQueueLength < 2) {
                $maxQueueLength = 2;
            }

            $upcomingQueue = $this->queueRepo->getUnplayedQueue($station);

            $lastSongId = null;
            $queueLength = 0;

            foreach ($upcomingQueue as $queueRow) {
                if ($queueRow->getSentToAutodj()) {
                    $expectedCueTime = $this->addDurationToTime(
                        $station,
                        CarbonImmutable::createFromTimestamp($queueRow->getTimestampCued(), $tzObject),
                        $queueRow->getDuration()
                    );

                    if (0 === $queueLength) {
                        $queueLength = 1;
                    }
                } else {
                    // Prevent the exact same track from being played twice during this loop
                    if ($lastSongId === $queueRow->getSongId()) {
                        $this->em->remove($queueRow);
                        continue;
                    }

                    $queueRow->setTimestampCued($expectedCueTime->getTimestamp());
                    $expectedCueTime = $this->addDurationToTime($station, $expectedCueTime, $queueRow->getDuration());

                    // Only append to queue length for uncued songs.
                    $queueLength++;
                }

                $queueRow->setTimestampPlayed($expectedPlayTime->getTimestamp());
                $this->em->persist($queueRow);

                $expectedPlayTime = $this->addDurationToTime($station, $expectedPlayTime, $queueRow->getDuration());

                $lastSongId = $queueRow->getSongId();
            }

            $this->em->flush();

            // Build the remainder of the queue.
            while ($queueLength < $maxQueueLength) {
                $queueRow = $this->cueNextSong($station, $expectedCueTime, $expectedPlayTime);
                if ($queueRow instanceof Entity\StationQueue) {
                    $this->em->persist($queueRow);

                    // Prevent the exact same track from being played twice during this loop
                    if ($lastSongId === $queueRow->getSongId()) {
                        $this->em->remove($queueRow);
                    } else {
                        $lastSongId = $queueRow->getSongId();

                        $expectedCueTime = $this->addDurationToTime(
                            $station,
                            $expectedCueTime,
                            $queueRow->getDuration()
                        );
                        $expectedPlayTime = $this->addDurationToTime(
                            $station,
                            $expectedPlayTime,
                            $queueRow->getDuration()
                        );
                    }
                } else {
                    break;
                }

                $this->em->flush();
                $queueLength++;
            }
        } finally {
            $lock->release();
            $this->logger->popProcessor();
        }
    }

    protected function addDurationToTime(Entity\Station $station, CarbonInterface $now, ?int $duration): CarbonInterface
    {
        $duration ??= 1;

        $startNext = $station->getBackendConfig()->getCrossfadeDuration();

        $now = $now->addSeconds($duration);
        return ($duration >= $startNext)
            ? $now->subMilliseconds((int)($startNext * 1000))
            : $now;
    }

    protected function cueNextSong(
        Entity\Station $station,
        CarbonInterface $expectedCueTime,
        CarbonInterface $expectedPlayTime
    ): ?Entity\StationQueue {
        $this->logger->debug(
            'Adding to station queue.',
            [
                'now' => (string)$expectedPlayTime,
            ]
        );

        // Push another test handler specifically for this one queue task.
        $testHandler = new TestHandler(LogLevel::DEBUG, true);
        $this->logger->pushHandler($testHandler);

        $event = new BuildQueue($station, $expectedCueTime, $expectedPlayTime);
        $this->dispatcher->dispatch($event);

        $this->logger->popHandler();

        $queueRow = $event->getNextSong();
        if ($queueRow instanceof Entity\StationQueue) {
            $queueRow->setTimestampCued($expectedCueTime->getTimestamp());
            $queueRow->setTimestampPlayed($expectedPlayTime->getTimestamp());

            $queueRow->setLog($testHandler->getRecords());
        }

        return $queueRow;
    }

    /**
     * Determine the next-playing song for this station based on its playlist rotation rules.
     *
     * @param BuildQueue $event
     */
    public function calculateNextSong(BuildQueue $event): void
    {
        $this->logger->info('AzuraCast AutoDJ is calculating the next song to play...');

        $station = $event->getStation();
        $expectedPlayTime = $event->getExpectedPlayTime();

        [$activePlaylistsByType, $oncePerXSongHistoryCount] = $this->getActivePlaylistsSortedByType($station);

        if (empty($activePlaylistsByType)) {
            $this->logger->error('No valid playlists detected. Skipping AutoDJ calculations.');
            return;
        }

        $recentPlaylistHistory = $this->queueRepo->getRecentPlaylists(
            $station,
            $oncePerXSongHistoryCount
        );

        $recentSongHistoryForDuplicatePrevention = $this->queueRepo->getRecentlyPlayedByTimeRange(
            $station,
            $expectedPlayTime,
            $station->getBackendConfig()->getDuplicatePreventionTimeRange()
        );

        $this->logRecentSongHistory(
            $recentPlaylistHistory,
            $recentSongHistoryForDuplicatePrevention
        );

        foreach (self::TYPES_TO_PLAY_BY_PRIORITY as $currentPlaylistType) {
            if (empty($activePlaylistsByType[$currentPlaylistType])) {
                continue;
            }

            [$eligiblePlaylists, $logPlaylists] = $this->filterEligiblePlaylists(
                $activePlaylistsByType,
                $currentPlaylistType,
                $expectedPlayTime,
                $recentPlaylistHistory
            );

            if (empty($eligiblePlaylists)) {
                continue;
            }

            $this->logPlayablePlaylists(
                $currentPlaylistType,
                $eligiblePlaylists,
                $logPlaylists
            );

            $this->weightedShuffle($eligiblePlaylists);

            // Loop through the playlists and attempt to play them with no duplicates first,
            // then loop through them again while allowing duplicates.
            foreach ([false, true] as $allowDuplicates) {
                $nextSong = $this->playNextSongFromEligiblePlaylists(
                    $currentPlaylistType,
                    $eligiblePlaylists,
                    $activePlaylistsByType,
                    $recentSongHistoryForDuplicatePrevention,
                    $expectedPlayTime,
                    $allowDuplicates
                );

                if ($event->setNextSong($nextSong)) {
                    $this->logger->info(
                        'Playable track found and registered.',
                        [
                            'next_song' => (string)$event,
                        ]
                    );
                    return;
                }
            }
        }

        $this->logger->error('No playable tracks were found.');
    }

    /**
     * Returns an array containing an array of  the active playlists sorted by playlist type
     * and the maximum amount of songs that need to be fetched from the song history for
     * playlists of type "once per x songs"
     *
     * @return mixed[]
     */
    protected function getActivePlaylistsSortedByType(Entity\Station $station): array
    {
        $playlistsByType = [];
        $oncePerXSongHistoryCount = 15;

        foreach ($station->getPlaylists() as $playlist) {
            /** @var Entity\StationPlaylist $playlist */
            if ($playlist->isPlayable()) {
                $type = $playlist->getType();

                if (Entity\StationPlaylist::TYPE_ONCE_PER_X_SONGS === $type) {
                    $oncePerXSongHistoryCount = max($oncePerXSongHistoryCount, $playlist->getPlayPerSongs());
                }

                $subType = ($playlist->getScheduleItems()->count() > 0) ? 'scheduled' : 'unscheduled';
                $playlistsByType[$type . '_' . $subType][$playlist->getId()] = $playlist;
            }
        }

        return [$playlistsByType, $oncePerXSongHistoryCount];
    }

    /**
     * Filters the supplied array of playlists to return ony the ones that should play now.
     * Returns an array with an array of the filtered playlists and an array containing detailed
     * information about each eligible playlist for logging purposes.
     *
     * @return mixed[]
     */
    protected function filterEligiblePlaylists(
        array $playlistsByType,
        string $type,
        CarbonInterface $expectedPlayTime,
        array $recentPlaylistHistory
    ): array {
        $eligiblePlaylists = [];
        $logPlaylists = [];

        foreach ($playlistsByType[$type] as $playlistId => $playlist) {
            /** @var Entity\StationPlaylist $playlist */
            if (!$this->scheduler->shouldPlaylistPlayNow($playlist, $expectedPlayTime, $recentPlaylistHistory)) {
                continue;
            }

            $eligiblePlaylists[$playlistId] = $playlist->getWeight();

            $logPlaylists[] = [
                'id' => $playlist->getId(),
                'name' => $playlist->getName(),
                'weight' => $playlist->getWeight(),
            ];
        }

        return [$eligiblePlaylists, $logPlaylists];
    }

    /**
     * Apply a weighted shuffle to the given array in the form:
     *  [ key1 => weight1, key2 => weight2 ]
     *
     * Based on: https://gist.github.com/savvot/e684551953a1716208fbda6c4bb2f344
     *
     * @param array $original
     */
    protected function weightedShuffle(array &$original): void
    {
        $new = $original;

        $max = 1.0 / mt_getrandmax();

        array_walk(
            $new,
            static function (&$value, $key) use ($max): void {
                $value = (mt_rand() * $max) ** (1.0 / $value);
            }
        );

        arsort($new);

        array_walk(
            $new,
            static function (&$value, $key) use ($original): void {
                $value = $original[$key];
            }
        );

        $original = $new;
    }

    protected function playNextSongFromEligiblePlaylists(
        string $currentPlaylistType,
        array $eligiblePlaylists,
        array $activePlaylistsByType,
        array $recentSongHistoryForDuplicatePrevention,
        CarbonInterface $expectedPlayTime,
        bool $allowDuplicates
    ): ?Entity\StationQueue {
        foreach ($eligiblePlaylists as $playlistId => $weight) {
            $playlist = $activePlaylistsByType[$currentPlaylistType][$playlistId];

            $nextSong = $this->playSongFromPlaylist(
                $playlist,
                $recentSongHistoryForDuplicatePrevention,
                $expectedPlayTime,
                $allowDuplicates
            );

            if ($nextSong !== null) {
                return $nextSong;
            }
        }

        return null;
    }

    /**
     * Given a specified (sequential or shuffled) playlist, choose a song from the playlist to play and return it.
     *
     * @param Entity\StationPlaylist $playlist
     * @param array $recentSongHistory
     * @param CarbonInterface $expectedPlayTime
     * @param bool $allowDuplicates Whether to return a media ID even if duplicates can't be prevented.
     */
    protected function playSongFromPlaylist(
        Entity\StationPlaylist $playlist,
        array $recentSongHistory,
        CarbonInterface $expectedPlayTime,
        bool $allowDuplicates = false
    ): ?Entity\StationQueue {
        if (Entity\StationPlaylist::SOURCE_REMOTE_URL === $playlist->getSource()) {
            return $this->getSongFromRemotePlaylist($playlist, $expectedPlayTime);
        }

        $validTrack = match ($playlist->getOrder()) {
            $playlist::ORDER_RANDOM => $this->getRandomMediaIdFromPlaylist(
                $playlist,
                $recentSongHistory,
                $allowDuplicates
            ),
            $playlist::ORDER_SEQUENTIAL => $this->getSequentialMediaIdFromPlaylist($playlist),
            $playlist::ORDER_SHUFFLE => $this->getShuffledMediaIdFromPlaylist(
                $playlist,
                $recentSongHistory,
                $allowDuplicates
            ),
            default => null
        };

        if (null === $validTrack) {
            $this->logger->warning(
                sprintf('Playlist "%s" did not return a playable track.', $playlist->getName()),
                [
                    'playlist_id' => $playlist->getId(),
                    'playlist_order' => $playlist->getOrder(),
                    'allow_duplicates' => $allowDuplicates,
                ]
            );
            return null;
        }

        $mediaToPlay = $this->em->find(Entity\StationMedia::class, $validTrack->media_id);
        if (!$mediaToPlay instanceof Entity\StationMedia) {
            return null;
        }

        $spm = $this->em->find(Entity\StationPlaylistMedia::class, $validTrack->spm_id);
        if ($spm instanceof Entity\StationPlaylistMedia) {
            $spm->played($expectedPlayTime->getTimestamp());
            $this->em->persist($spm);
        }

        $playlist->setPlayedAt($expectedPlayTime->getTimestamp());
        $this->em->persist($playlist);

        $stationQueueEntry = Entity\StationQueue::fromMedia($playlist->getStation(), $mediaToPlay);
        $stationQueueEntry->setPlaylist($playlist);
        $this->em->persist($stationQueueEntry);

        return $stationQueueEntry;
    }

    protected function getSongFromRemotePlaylist(
        Entity\StationPlaylist $playlist,
        CarbonInterface $expectedPlayTime
    ): ?Entity\StationQueue {
        $mediaToPlay = $this->getMediaFromRemoteUrl($playlist);

        if (is_array($mediaToPlay)) {
            [$mediaUri, $mediaDuration] = $mediaToPlay;

            $playlist->setPlayedAt($expectedPlayTime->getTimestamp());
            $this->em->persist($playlist);

            $stationQueueEntry = new Entity\StationQueue(
                $playlist->getStation(),
                Entity\Song::createFromText('Remote Playlist URL')
            );

            $stationQueueEntry->setPlaylist($playlist);
            $stationQueueEntry->setAutodjCustomUri($mediaUri);
            $stationQueueEntry->setDuration($mediaDuration);

            $this->em->persist($stationQueueEntry);

            return $stationQueueEntry;
        }

        return null;
    }

    /**
     * Returns either an array containing the URL of a remote stream and the duration,
     * an array with a media id and the duration or null if no media has been found.
     *
     * @return mixed[]|null
     */
    protected function getMediaFromRemoteUrl(Entity\StationPlaylist $playlist): ?array
    {
        $remoteType = $playlist->getRemoteType() ?? Entity\StationPlaylist::REMOTE_TYPE_STREAM;

        // Handle a raw stream URL of possibly indeterminate length.
        if (Entity\StationPlaylist::REMOTE_TYPE_STREAM === $remoteType) {
            // Annotate a hard-coded "duration" parameter to avoid infinite play for scheduled playlists.
            $duration = $this->scheduler->getPlaylistScheduleDuration($playlist);
            return [$playlist->getRemoteUrl(), $duration];
        }

        // Handle a remote playlist containing songs or streams.
        $queueCacheKey = 'playlist_queue.' . $playlist->getId();

        $mediaQueue = $this->cache->get($queueCacheKey);
        if (empty($mediaQueue)) {
            $mediaQueue = [];

            $playlistRemoteUrl = $playlist->getRemoteUrl();
            if (null !== $playlistRemoteUrl) {
                $playlistRaw = file_get_contents($playlistRemoteUrl);
                if (false !== $playlistRaw) {
                    $mediaQueue = PlaylistParser::getSongs($playlistRaw);
                }
            }
        }

        $mediaId = null;
        if (!empty($mediaQueue)) {
            $mediaId = array_shift($mediaQueue);
        }

        // Save the modified cache, sans the now-missing entry.
        $this->cache->set($queueCacheKey, $mediaQueue, 6000);

        return ($mediaId)
            ? [$mediaId, 0]
            : null;
    }

    protected function getRandomMediaIdFromPlaylist(
        Entity\StationPlaylist $playlist,
        array $recentSongHistory,
        bool $allowDuplicates
    ): ?Entity\Api\StationPlaylistQueue {
        $mediaQueue = $this->spmRepo->getQueue($playlist);

        if ($playlist->getAvoidDuplicates()) {
            return $this->preventDuplicates($mediaQueue, $recentSongHistory, $allowDuplicates);
        }

        return array_shift($mediaQueue);
    }

    protected function getSequentialMediaIdFromPlaylist(
        Entity\StationPlaylist $playlist
    ): ?Entity\Api\StationPlaylistQueue {
        $mediaQueue = $this->spmRepo->getQueue($playlist);
        if (empty($mediaQueue)) {
            $mediaQueue = $this->spmRepo->resetQueue($playlist);
        }

        return array_shift($mediaQueue);
    }

    protected function getShuffledMediaIdFromPlaylist(
        Entity\StationPlaylist $playlist,
        array $recentSongHistory,
        bool $allowDuplicates
    ): ?Entity\Api\StationPlaylistQueue {
        $mediaQueue = $this->spmRepo->getQueue($playlist);
        if (empty($mediaQueue)) {
            $mediaQueue = $this->spmRepo->resetQueue($playlist);
        }

        if ($playlist->getAvoidDuplicates()) {
            if ($allowDuplicates) {
                $this->logger->warning(
                    'Duplicate prevention yielded no playable song; resetting song queue.'
                );

                $mediaQueue = $this->spmRepo->resetQueue($playlist);
            }

            return $this->preventDuplicates($mediaQueue, $recentSongHistory, $allowDuplicates);
        }

        return array_shift($mediaQueue);
    }

    /**
     * @param Entity\Api\StationPlaylistQueue[] $eligibleTracks
     * @param array $playedTracks
     * @param bool $allowDuplicates Whether to return a media ID even if duplicates can't be prevented.
     */
    protected function preventDuplicates(
        array $eligibleTracks = [],
        array $playedTracks = [],
        bool $allowDuplicates = false
    ): ?Entity\Api\StationPlaylistQueue {
        if (empty($eligibleTracks)) {
            $this->logger->debug('Eligible song queue is empty!');
            return null;
        }

        $latestSongIdsPlayed = [];

        foreach ($playedTracks as $playedTrack) {
            $songId = $playedTrack['song_id'];

            if (!isset($latestSongIdsPlayed[$songId])) {
                $latestSongIdsPlayed[$songId] = $playedTrack['timestamp_cued'];
            }
        }

        /** @var Entity\Api\StationPlaylistQueue[] $notPlayedEligibleTracks */
        $notPlayedEligibleTracks = [];

        foreach ($eligibleTracks as $mediaId => $track) {
            $songId = $track->song_id;
            if (isset($latestSongIdsPlayed[$songId])) {
                continue;
            }

            $notPlayedEligibleTracks[$mediaId] = $track;
        }

        $validTrack = self::getDistinctTrack($notPlayedEligibleTracks, $playedTracks);

        if (null !== $validTrack) {
            $this->logger->info(
                'Found track that avoids duplicate title and artist.',
                [
                    'media_id' => $validTrack->media_id,
                    'title' => $validTrack->title,
                    'artist' => $validTrack->artist,
                ]
            );

            return $validTrack;
        }

        // If we reach this point, there's no way to avoid a duplicate title and artist.
        if ($allowDuplicates) {
            /** @var Entity\Api\StationPlaylistQueue[] $mediaIdsByTimePlayed */
            $mediaIdsByTimePlayed = [];

            // For each piece of eligible media, get its latest played timestamp.
            foreach ($eligibleTracks as $track) {
                $songId = $track->song_id;
                $trackKey = $latestSongIdsPlayed[$songId] ?? 0;
                $mediaIdsByTimePlayed[$trackKey] = $track;
            }

            ksort($mediaIdsByTimePlayed);

            $validTrack = array_shift($mediaIdsByTimePlayed);

            // Pull the lowest value, which corresponds to the least recently played song.
            if (null !== $validTrack) {
                $this->logger->warning(
                    'No way to avoid same title OR same artist; using least recently played song.',
                    [
                        'media_id' => $validTrack->media_id,
                        'title' => $validTrack->title,
                        'artist' => $validTrack->artist,
                    ]
                );

                return $validTrack;
            }
        }

        return null;
    }

    /**
     * @param BuildQueue $event
     */
    public function getNextSongFromRequests(BuildQueue $event): void
    {
        $expectedPlayTime = $event->getExpectedPlayTime();

        $request = $this->requestRepo->getNextPlayableRequest($event->getStation(), $expectedPlayTime);
        if (null === $request) {
            return;
        }

        $this->logger->debug(sprintf('Queueing next song from request ID %d.', $request->getId()));

        $stationQueueEntry = Entity\StationQueue::fromRequest($request);
        $this->em->persist($stationQueueEntry);

        $request->setPlayedAt($expectedPlayTime->getTimestamp());
        $this->em->persist($request);

        $event->setNextSong($stationQueueEntry);
    }

    /**
     * Given an array of eligible tracks, return the first ID that doesn't have a duplicate artist/
     *   title with any of the previously played tracks.
     *
     * Both should be in the form of an array, i.e.:
     *  [ 'id' => ['artist' => 'Foo', 'title' => 'Fighters'] ]
     *
     * @param Entity\Api\StationPlaylistQueue[] $eligibleTracks
     * @param array $playedTracks
     *
     */
    public static function getDistinctTrack(
        array $eligibleTracks,
        array $playedTracks
    ): ?Entity\Api\StationPlaylistQueue {
        $artistSeparators = [
            ', ',
            ' feat ',
            ' feat. ',
            ' & ',
            ' vs. ',
        ];
        $dividerString = chr(7);

        $artists = [];
        $titles = [];
        $latestSongIdsPlayed = [];

        foreach ($playedTracks as $playedTrack) {
            $title = trim($playedTrack['title']);
            $titles[$title] = $title;

            $artistParts = explode(
                $dividerString,
                str_replace($artistSeparators, $dividerString, $playedTrack['artist'])
            );

            foreach ($artistParts as $artist) {
                $artist = trim($artist);
                if (!empty($artist)) {
                    $artists[$artist] = $artist;
                }
            }

            $songId = $playedTrack['song_id'];
            if (!isset($latestSongIdsPlayed[$songId])) {
                $latestSongIdsPlayed[$songId] = $playedTrack['timestamp_played'];
            }
        }

        /** @var Entity\Api\StationPlaylistQueue[] $eligibleTracksWithoutSameTitle */
        $eligibleTracksWithoutSameTitle = [];

        foreach ($eligibleTracks as $track) {
            // Avoid all direct title matches.
            $title = trim($track->title);

            if (isset($titles[$title])) {
                continue;
            }

            // Attempt to avoid an artist match, if possible.
            $artist = trim($track->artist);

            $artistMatchFound = false;
            if (!empty($artist)) {
                $artistParts = explode($dividerString, str_replace($artistSeparators, $dividerString, $artist));
                foreach ($artistParts as $artist) {
                    $artist = trim($artist);
                    if (empty($artist)) {
                        continue;
                    }

                    if (isset($artists[$artist])) {
                        $artistMatchFound = true;
                        break;
                    }
                }
            }

            if (!$artistMatchFound) {
                return $track;
            }

            $songId = $track->song_id;
            $trackKey = $latestSongIdsPlayed[$songId] ?? 0;
            $eligibleTracksWithoutSameTitle[$trackKey] = $track;
        }

        ksort($eligibleTracksWithoutSameTitle);
        return array_shift($eligibleTracksWithoutSameTitle);
    }

    protected function logRecentSongHistory(
        array $recentPlaylistHistory,
        array $recentSongHistoryForDuplicatePrevention
    ): void {
        $this->logger->debug(
            'AutoDJ recent song playback history',
            [
                'history_once_per_x_songs' => $recentPlaylistHistory,
                'history_duplicate_prevention' => $recentSongHistoryForDuplicatePrevention,
            ]
        );
    }

    protected function logPlayablePlaylists(
        string $type,
        array $eligiblePlaylists,
        array $logPlaylists
    ): void {
        $this->logger->info(
            sprintf(
                '%d playable playlist(s) of type "%s" found.',
                count($eligiblePlaylists),
                $type
            ),
            ['playlists' => $logPlaylists]
        );
    }
}
