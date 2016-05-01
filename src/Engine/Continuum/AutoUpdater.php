<?php
declare(strict_types=1);
namespace Airship\Engine\Continuum;

use \Airship\Alerts\Hail\NoAPIResponse;
use Airship\Engine\{
    Bolt\Log,
    Hail,
    State
};
use \GuzzleHttp\Client;
use \GuzzleHttp\Psr7\Response;
use \GuzzleHttp\Exception\TransferException;
use \ParagonIE\ConstantTime\Base64;
use \ParagonIE\Halite\{
    File,
    Util
};
use \Psr\Log\LogLevel;

/**
 * Class AutoUpdater
 *
 * The base class for the auto-updaters.
 *
 * @package Airship\Engine\Continuum
 */
abstract class AutoUpdater
{
    use Log;

    const TYPE_ENGINE = 'engine';
    const TYPE_CABIN = 'cabin';
    const TYPE_GADGET = 'gadget';
    const TYPE_MOTIF = 'motif';

    protected $pharAlias;
    protected $name;
    protected $supplier;
    protected $hail;
    protected $type = '';
    protected static $channels = [];

    /**
     * Automatic script execution
     *
     * @param array $autoRun
     * @return mixed
     */
    protected function autoRunScript(array $autoRun)
    {
        $ret = null;
        // Get a unique temporary file
        do {
            $script = \tempnam(ROOT . DIRECTORY_SEPARATOR . 'tmp', 'update-script-');
        } while (\file_exists($script));

        // What kind of autoRun script is it?
        switch ($autoRun['type']) {
            case 'php':
                \file_put_contents($script.'.php', Base64::decode($autoRun['data']));
                $ret = Sandbox::safeRequire($script.'.php');
                \unlink($script.'.php');
                break;
            case 'sh':
                \file_put_contents($script.'.sh', Base64::decode($autoRun['data']));
                $ret = \shell_exec($script.'.sh');
                \unlink($script.'.sh');
                break;
            case 'pgsql':
            case 'mysql':
                \file_put_contents($script.'.'.$autoRun['type'], Base64::decode($autoRun['data']));
                $ret = Sandbox::runSQLFile($script.'.'.$autoRun['type'], $autoRun['type']);
                \unlink($script.'.'.$autoRun['type']);
                break;
        }
        return $ret;
    }

    /**
     * After we finish our update, we should bring the site back online:
     */
    protected function bringSiteBackUp()
    {
        \unlink(
            ROOT.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'site_down.txt'
        );
        \clearstatcache();
    }

    /**
     * Let's bring the site down while we're upgrading:
     */
    protected function bringSiteDown()
    {
        \file_put_contents(
            ROOT.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'site_down.txt',
            \date('Y-m-d\TH:i:s')
        );
        \clearstatcache();
    }

    /**
     * Should this automatic update be permitted?
     *
     * @param UpdateInfo $info
     * @param string $currentVersion
     * @return bool
     */
    protected function checkVersionSettings(
        UpdateInfo $info,
        string $currentVersion
    ): bool {
        $state = State::instance();
        $nextVersion = $info->getVersion();
        $version = new Version($currentVersion);

        // If this isn't an upgrade at all, don't apply it.
        if (!$version->isUpgrade($nextVersion)) {
            return false;
        }
        if ($version->isMajorUpgrade($nextVersion)) {
            return !empty($state->universal['auto-update']['major']);
        }
        if ($version->isMinorUpgrade($nextVersion)) {
            return !empty($state->universal['auto-update']['minor']);
        }
        if ($version->isPatchUpgrade($nextVersion)) {
            return !empty($state->universal['auto-update']['patch']);
        }
        return false;
    }

    /**
     * Download an update into a temp file
     *
     * @param UpdateInfo $update
     * @param string $apiEndpoint
     * @return UpdateFile
     */
    public function downloadUpdateFile(
        UpdateInfo $update,
        string $apiEndpoint = 'download'
    ): UpdateFile {
        if (IDE_HACKS) {
            $this->hail = new Hail(new Client);
        }
        try {
            $version = $update->getVersion();
            $response = $this->hail->post(
                $update->getChannel() . API::get($apiEndpoint),
                [
                    'version' => $version
                ]
            );
            if ($response instanceof Response) {
                $code = $response->getStatusCode();
                if ($code >= 200 && $code < 300) {
                    $outFile = \tempnam(\sys_get_temp_dir(), 'airship-') . '.phar';
                    $body = (string) $response->getBody();
                    $saved = \file_put_contents($outFile, $body);
                    if ($saved !== false) {
                        // To prevent TOCTOU issues down the line
                        $hash = Util::hash($body);
                        $body = null;
                        \clearstatcache();

                        return new UpdateFile([
                            'path' => $outFile,
                            'version' => $version,
                            'hash' => $hash,
                            'size' => \filesize($outFile)
                        ]);
                    }
                }
            }
            // If we're still here...
            throw new TransferException();
        } catch (TransferException $ex) {
            $this->log(
                'Automatic update failure.',
                LogLevel::WARNING,
                [
                    'exception' => \Airship\throwableToArray($ex),
                    'channel' => $update->getChannel()
                ]
            );
            // Rethrow it to prevent errors on return type
            throw $ex;
        }
    }

    /**
     * Get the channels
     *
     * @param string $name
     * @return Channel
     * @throws NoAPIResponse
     */
    protected function getChannel(string $name): Channel
    {
        if (empty(self::$channels)) {
            $config = \Airship\loadJSON(ROOT . '/config/channels.json');
            foreach ($config as $chName => $chConfig) {
                self::$channels[$chName] = new Channel($this, $chName, $chConfig);
            }
        }
        if (isset(self::$channels[$name])) {
            return self::$channels[$name];
        }
        throw new NoAPIResponse(
            \trk('errors.hail.no_channel_configured')
        );
    }

    /**
     * Sort updates by version (newest to latest)
     *
     * @param UpdateInfo[] ...$updates
     * @return UpdateInfo[]
     */
    protected function sortUpdatesByVersion(UpdateInfo ...$updates): array
    {
        \uasort($updates, function(UpdateInfo $a, UpdateInfo $b) {
            return $a->getVersionExpanded() <=> $b->getVersionExpanded();
        });
        return $updates;
    }
    
    /**
     * Are any updates available?
     *
     * @param string $supplier
     * @param string $packageName
     * @param string $minVersion
     * @param string $apiEndpoint
     * 
     * @return UpdateInfo[]
     * 
     * @throws \Airship\Alerts\Hail\NoAPIResponse
     */
    public function updateCheck(
        string $supplier = '',
        string $packageName = '',
        string $minVersion = '',
        string $apiEndpoint = 'version'
    ): array {
        if (IDE_HACKS) {
            $this->hail = new Hail(new Client);
            $this->supplier = new Supplier('');
        }
        if (empty($supplier)) {
            $supplier = $this->supplier->getName();
        }
        $channelsConfigured = $this->supplier->getChannels();
        if (empty($channelsConfigured)) {
            throw new NoAPIResponse(
                \trk('errors.hail.no_channel_configured')
            );
        }
        foreach ($channelsConfigured as $channelName) {
            $channel = $this->getChannel($channelName);
            foreach ($channel->getAllURLs() as $ch) {
                try {
                    $response = $this->hail->post(
                        $ch . API::get($apiEndpoint),
                        [
                            'type' => $this->type,
                            'supplier' => $supplier,
                            'package' => $packageName,
                            'minimum' => $minVersion
                        ]
                    );
                    if ($response instanceof Response) {
                        $code = $response->getStatusCode();
                        if ($code >= 200 && $code < 300) {
                            $body = \json_decode((string)$response->getBody(), true);
                            $updates = [];
                            foreach ($body['versions'] as $update) {
                                $updates [] = new UpdateInfo(
                                    $update,
                                    $ch
                                );
                            }
                            return $this->sortUpdatesByVersion($updates);
                        }
                    }
                } catch (TransferException $ex) {
                    // Log? Definitely suppress, however.
                    $this->log(
                        'Automatic update failure. (' . \get_class($ex) . ')',
                        LogLevel::WARNING,
                        [
                            'exception' => \Airship\throwableToArray($ex),
                            'channelName' => $channelName,
                            'channel' => $ch
                        ]
                    );
                }
            }
        }
        throw new NoAPIResponse(
            \trk('errors.hail.no_channel_responded')
        );
    }

    /**
     * Verify the Ed25519 signature of the update file against the
     * supplier's public key.
     *
     * @param UpdateInfo $info
     * @param UpdateFile $file
     * @return bool
     */
    public function verifyUpdateSignature(
        UpdateInfo $info,
        UpdateFile $file
    ): bool {
        $ret = false;
        if (IDE_HACKS) {
            $this->supplier = new Supplier('');
        }

        foreach ($this->supplier->getSigningKeys() as $key) {
            $ret = $ret || File::verify(
                $file->getPath(),
                $key['key'],
                $info->getSignature(true)
            );
        }
        return $ret;
    }
}