<?php
namespace phpbu\App;

use DOMElement;
use DOMXPath;
use phpbu\Util\Cli;
use phpbu\Util\String;

/**
 *
 * Wrapper for the phpbu XML configuration file.
 *
 * Example XML configuration file:
 * <code>
 * <?xml version="1.0" encoding="UTF-8" ?>
 * <phpbu bootstrap="backup/bootstrap.php"
 *        verbose="true">
 *
 *   <php>
 *     <includePath>.</includePath>
 *     <ini name="max_execution_time" value="0" />
 *   </php>
 *
 *   <logging>
 *     <log type="json" target="/tmp/logfile.json" />
 *     <log type="plain" target="/tmp/logfile.txt" />
 *   </logging>
 *
 *   <backups>
 *     <backup>
 *       <source type="mysql">
 *         <option name="databases" value="dbname" />
 *         <option name="tables" value="" />
 *         <option name="ignoreTables" value="" />
 *         <option name="structureOnly" value="dbname.table1,dbname.table2" />
 *       </source>
 *
 *       <target dirname="/tmp/backup" filename="mysqldump-%Y%m%d-%H%i.sql" compress="bzip2" />
 *
 *       <sanitycheck type="SomeName" value="10MB" />
 *       <sanitycheck type="SomeName" value="20MB" />
 *
 *       <sync type="rsync" skipOnSanityFail="true">
 *         <option name="user" value="user.name" />
 *         <option name="password" value="topsecret" />
 *       </sync>
 *
 *       <cleanup skipOnSanityFail="true">
 *         <option name="amount" value="50" />
 *         <option name="outdated" value="2W" />
 *       </cleanup>
 *     </backup>
 *   </backups>
 * </phpbu>
 * </code>
 *
 * @package    phpbu
 * @subpackage App
 * @author     Sebastian Feldmann <sebastian@phpbu.de>
 * @copyright  Sebastian Feldmann <sebastian@phpbu.de>
 * @license    http://www.opensource.org/licenses/BSD-3-Clause  The BSD 3-Clause License
 * @link       http://phpbu.de/
 * @since      Class available since Release 1.0.0
 */
class Configuration
{
    /**
     * Path to config file.
     *
     * @var string
     */
    private $filename;

    /**
     * Config file DOMDocument
     *
     * @var \DOMDocument
     */
    private $document;

    /**
     * Xpath to navigate the config DOM.
     *
     * @var \DOMXPath
     */
    private $xpath;

    /**
     * Constructor
     *
     * @param  string $filename
     */
    public function __construct($filename)
    {
        $this->filename = $filename;
        $this->document = $this->loadXmlFile($filename);
        $this->xpath    = new DOMXPath($this->document);
    }

    /**
     * Get the phpbu application settings.
     *
     * @return array
     */
    public function getAppSettings()
    {
        $settings = array();
        $root     = $this->document->documentElement;

        if ($root->hasAttribute('bootstrap')) {
            $settings['bootstrap'] = $this->toAbsolutePath((string) $root->getAttribute('bootstrap'));
        }
        if ($root->hasAttribute('verbose')) {
            $settings['verbose'] = String::toBoolean((string) $root->getAttribute('verbose'), false);
        }
        if ($root->hasAttribute('colors')) {
            $settings['colors'] = String::toBoolean((string) $root->getAttribute('colors'), false);
        }
        return $settings;
    }

    /**
     * Get the php settings.
     * Checking for include_path and ini settings.
     *
     * @return array
     */
    public function getPhpSettings()
    {
        $settings = array(
            'include_path' => array(),
            'ini'          => array(),
        );
        foreach ($this->xpath->query('php/includePath') as $includePath) {
            $path = (string) $includePath->nodeValue;
            if ($path) {
                $settings['include_path'][] = $this->toAbsolutePath($path);
            }
        }
        foreach ($this->xpath->query('php/ini') as $ini) {
            /** @var DOMElement $ini */
            $name  = (string) $ini->getAttribute('name');
            $value = (string) $ini->getAttribute('value');

            $settings['ini'][$name] = $value;
        }
        return $settings;
    }

    /**
     * Get the backup configurations.
     *
     * @return array
     */
    public function getBackupSettings()
    {
        $settings = array();
        foreach ($this->xpath->query('backups/backup') as $backupNode) {
            $settings[] = $this->getBackupConfig($backupNode);
        }
        return $settings;
    }

    /**
     * Get the config for a single backup node.
     *
     * @param  \DOMElement $backupNode
     * @throws \phpbu\App\Exception
     * @return array
     */
    private function getBackupConfig(DOMElement $backupNode)
    {
        // stop on error
        $stopOnError = String::toBoolean((string) $backupNode->getAttribute('stopOnError'), false);
        $backupName  = $backupNode->getAttribute('name');
        // get source configuration
        $source  = array();
        $sources = $backupNode->getElementsByTagName('source');
        if ($sources->length !== 1) {
            throw new Exception('backup requires exactly one source config');
        }
        /** @var DOMElement $sourceNode */
        $sourceNode = $sources->item(0);
        $type       = (string) $sourceNode->getAttribute('type');
        if (!$type) {
            throw new Exception('source requires type attribute');
        }
        $source['type']    = $type;
        $source['options'] = $this->getOptions($sourceNode);

        // get target configuration
        $targets = $backupNode->getElementsByTagName('target');
        if ($targets->length !== 1) {
            throw new Exception('backup requires exactly one target config');
        }
        /** @var DOMElement $targetNode */
        $targetNode = $targets->item(0);
        $compress   = (string) $targetNode->getAttribute('compress');
        $filename   = (string) $targetNode->getAttribute('filename');
        $dirname    = (string) $targetNode->getAttribute('dirname');
        if ($dirname) {
            $dirname = $this->toAbsolutePath($dirname);
        }

        $target = array(
            'dirname'  => $dirname,
            'filename' => $filename,
            'compress' => $compress,
        );

        // get check information
        $checks = array();
        /** @var DOMElement $checkNode */
        foreach ($backupNode->getElementsByTagName('check') as $checkNode) {
            $type      = (string) $checkNode->getAttribute('type');
            $value     = (string) $checkNode->getAttribute('value');
            // skip invalid sanity checks
            if (!$type || !$value) {
                continue;
            }
            $checks[] = array('type' => $type, 'value' => $value);
        }

        // get sync configurations
        $syncs = array();
        /** @var DOMElement $syncNode */
        foreach ($backupNode->getElementsByTagName('sync') as $syncNode) {
            $sync = array(
                'type'            => (string) $syncNode->getAttribute('type'),
                'skipOnCheckFail' => String::toBoolean((string) $syncNode->getAttribute('skipOnCheckFail'), true),
                'options'         => array()
            );

            $sync['options'] = $this->getOptions($syncNode);
            $syncs[]         = $sync;
        }

        // get cleanup configuration
        $cleanup = array();
        /** @var DOMElement $cleanupNode */
        foreach ($backupNode->getElementsByTagName('cleanup') as $cleanupNode) {
            $cleanup = array(
                'type'            => (string) $cleanupNode->getAttribute('type'),
                'skipOnCheckFail' => String::toBoolean((string) $cleanupNode->getAttribute('skipOnCheckFail'), true),
                'skipOnSyncFail'  => String::toBoolean((string) $cleanupNode->getAttribute('skipOnSyncFail'), true),
                'options'         => array()
            );
            $cleanup['options'] = $this->getOptions($cleanupNode);
        }

        return array(
            'name'        => $backupName,
            'stopOnError' => $stopOnError,
            'source'      => $source,
            'target'      => $target,
            'checks'      => $checks,
            'syncs'       => $syncs,
            'cleanup'     => $cleanup,
        );
    }

    /**
     * Extracts all option tags.
     *
     * @param  DOMElement $node
     * @return array
     */
    protected function getOptions(DOMElement $node)
    {
        $options = array();
        /** @var DOMElement $optionNode */
        foreach ($node->getElementsByTagName('option') as $optionNode) {
            $name           = (string) $optionNode->getAttribute('name');
            $value          = (string) $optionNode->getAttribute('value');
            $options[$name] = $value;
        }
        return $options;
    }

    /**
     * Get the log configuration.
     *
     * @return array
     */
    public function getLoggingSettings()
    {
        $loggers = array();
        /** @var DOMElement $logNode */
        foreach ($this->xpath->query('logging/log') as $logNode) {
            $log = array(
                'type'    => (string) $logNode->getAttribute('type'),
                'options' => array(),
            );
            $tarAtr = (string) $logNode->getAttribute('target');
            if (!empty($tarAtr)) {
                $log['options']['target'] = $this->toAbsolutePath($tarAtr);
            }

            /** @var DOMElement $optionNode */
            foreach ($logNode->getElementsByTagName('option') as $optionNode) {
                $name  = (string) $optionNode->getAttribute('name');
                $value = (string) $optionNode->getAttribute('value');
                // check for path option
                if ('target' == $name) {
                    $value = $this->toAbsolutePath($value);
                }
                $log['options'][$name] = $value;
            }

            $loggers[] = $log;
        }
        return $loggers;
    }

    /**
     * Converts a path to an absolute one if necessary.
     *
     * @param  string  $path
     * @param  boolean $useIncludePath
     * @return string
     */
    protected function toAbsolutePath($path, $useIncludePath = false)
    {
        return Cli::toAbsolutePath($path, dirname($this->filename), $useIncludePath);
    }

    /**
     * Load the XML-File.
     *
     * @param  string $filename
     * @throws \phpbu\App\Exception
     * @return \DOMDocument
     */
    private function loadXmlFile($filename)
    {
        $reporting = error_reporting(0);
        $contents  = file_get_contents($filename);
        error_reporting($reporting);

        if ($contents === false) {
            throw new Exception(sprintf('Could not read "%s".', $filename));
        }

        $document  = new \DOMDocument;
        $message   = '';
        $internal  = libxml_use_internal_errors(true);
        $reporting = error_reporting(0);

        $document->documentURI = $filename;
        $loaded                = $document->loadXML($contents);

        foreach (libxml_get_errors() as $error) {
            $message .= "\n" . $error->message;
        }

        libxml_use_internal_errors($internal);
        error_reporting($reporting);

        if ($loaded === false || $message !== '') {
            throw new Exception(
                sprintf(
                    'Error loading file "%s".%s',
                    $filename,
                    $message != '' ? "\n" . $message : ''
                )
            );
        }
        return $document;
    }
}
