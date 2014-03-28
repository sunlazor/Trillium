<?php

/**
 * Part of the Trillium
 *
 * @author  Kilte Leichnam <nwotnbm@gmail.com>
 * @package Trillium
 */

namespace Trillium\Console\Command;

use Assetic\Asset\AssetCollection;
use Assetic\Asset\FileAsset;
use Assetic\Filter\FilterInterface;
use Assetic\Filter\Yui\CssCompressorFilter;
use Assetic\Filter\Yui\JsCompressorFilter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Assets Class
 *
 * @package Trillium\Console\Command
 */
class Assets extends Command
{

    /**
     * @var string Source directory
     */
    private $source;

    /**
     * @var string Public directory
     */
    private $public;

    /**
     * @var string Cache directory
     */
    private $cache;

    /**
     * @var array Assets configuration
     */
    private $conf;

    /**
     * @var array Filters configuration (from file)
     */
    private $confFilters;

    /**
     * @var array Loaded filters
     */
    private $filters = [];

    /**
     * @var array Default filters configuration
     */
    private $filtersConf = [
        'global'             => [
            'yui-path'  => null,
            'java-path' => '/usr/bin/java',
        ],
        'yui-js-compressor'  => [
            'charset'              => null,
            'linebreak'            => null,
            'stack-size'           => null,
            'nomunge'              => null,
            'disable-optimization' => null,
            'preserve-semi'        => null,
        ],
        'yui-css-compressor' => [
            'charset'    => null,
            'linebreak'  => null,
            'stack-size' => null,
        ],
    ];

    /**
     * @var array Output messages
     */
    private $messages = [
        'wrong_ignore_value' => '<fg=red>[EE]</fg=red> Wrong ignore value given',
        'invalid_src_dir'    => '<fg=red>[EE]</fg=red> Source directory does not exists',
        'invalid_pub_dir'    => '<fg=red>[EE]</fg=red> Public directory does not exists',
        'invalid_cache_dir'  => '<fg=red>[EE]</fg=red> Cache directory does not exists',
        'ignore'             => '<info>Ignore %s</info>',
        'src_dir'            => '<info>[OK]</info> Source directory: %s',
        'pub_dir'            => '<info>[OK]</info> Public directory: %s',
        'build'              => "\nWill now build...",
        'assets_type'        => "\nType: %s",
        'not_found'          => '<fg=red>[WW]</fg=red> No assets found.',
        'found'              => '%s assets found',
        'overwrite_asset'    => "\t<fg=red>[WW]</fg=red> Overwrite \"%s\" by \"%s\"",
        'found_asset'        => "\tFound: %s \"%s\" with \"%s\" priority",
        'dump_assets'        => 'Dump "%s" into "%s"... ',
        'dump_success'       => '<info>[OK]</info>',
        'dump_failed'        => '<fg=red>[FAIL]</fg=red>',
        'success'            => '<info>Success</info>',
        'failed'             => '<fg=red>Nothing to build</fg=red>',
    ];

    /**
     * Constructor
     *
     * @param string $source Source directory
     * @param string $public Public directory
     * @param string $cache  Cache directory
     * @param array  $conf   Configuration
     *
     * @throws \LogicException
     * @return self
     */
    public function __construct($source, $public, $cache, array $conf)
    {
        $this->source = $source;
        $this->public = $public;
        $this->cache  = $cache;
        // Load filters configuration
        $this->conf = $conf;
        if (isset($this->conf['filters'])) {
            $this->confFilters = $this->conf['filters'];
            foreach ($this->filtersConf as $key => $item) {
                // Configuration for a filter is missing
                if (!array_key_exists($key, $this->confFilters)) {
                    $this->confFilters[$key] = $item;
                } elseif (is_array($this->confFilters[$key])) {
                    foreach ($this->filtersConf[$key] as $name => $value) {
                        // Option for a filter configuration is missing
                        if (!array_key_exists($name, $this->confFilters[$key])) {
                            $this->confFilters[$key][$name] = $value;
                        }
                    }
                } else {
                    throw new \LogicException('Unable to read the configuration file');
                }
            }
            unset($this->conf['filters']);
        } else {
            $this->confFilters = $this->filtersConf;
        }
        parent::__construct('assets');
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setDescription('Build assets via assetic')
            ->addOption(
                'ignore',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Ignore files.' . "\n"
                . '"js" for javascript files' . "\n"
                . '"css" for css file'
            )
            ->addOption(
                'javascript',
                'j',
                InputOption::VALUE_OPTIONAL,
                'Javascript result filename',
                'scripts.js'
            )
            ->addOption(
                'stylesheet',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Styles result filename',
                'styles.css'
            )->addOption(
                'disable-cache',
                'd',
                InputOption::VALUE_NONE,
                'Ignore and clear cache'
            );
    }

    /**
     * {@inheritdoc}
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $errors       = [];
        $cacheEnabled = !$input->getOption('disable-cache');
        $ignore       = $input->getOption('ignore');
        $names        = [
            'css' => $input->getOption('stylesheet'),
            'js'  => $input->getOption('javascript'),
        ];
        if ($ignore !== null && !in_array($ignore, ['css', 'js'])) {
            $errors[] = $this->messages['wrong_ignore_value'];
        }
        if ($this->source === false) {
            $errors[] = $this->messages['invalid_src_dir'];
        }
        if ($this->public === false) {
            $errors[] = $this->messages['invalid_pub_dir'];
        }
        if ($this->cache === false) {
            $errors[] = $this->messages['invalid_cache_dir'];
        }
        if (!empty($errors)) {
            $output->writeln($errors);

            return 1;
        }
        $this->source = rtrim($this->source, '\/') . '/';
        $this->public = rtrim($this->public, '\/') . '/';
        $this->cache  = rtrim($this->cache, '\/') . '/';
        if ($ignore !== null) {
            $output->writeln(sprintf($this->messages['ignore'], $ignore));
        }
        $output->writeln(
            [
                sprintf($this->messages['src_dir'], $this->source),
                sprintf($this->messages['pub_dir'], $this->public),
                $this->messages['build']
            ]
        );
        $assets     = $ignore === null ? ['js', 'css'] : ($ignore === 'css' ? ['js'] : ['css']);
        $i          = 0;
        $filesystem = new Filesystem();
        if (!$cacheEnabled) {
            // Remove cache
            $filesystem->remove(
                array_map(
                    function ($name) {
                        return $this->cache . $name;
                    },
                    array_diff(scandir($this->cache), ['.', '..', '.gitignore'])
                )
            );
        }
        $checksums    = [];
        $checksumsNew = [];
        if (is_file($this->cache . 'checksums.json')) {
            $checksumsRaw = @file_get_contents($this->cache . 'checksums.json');
            if ($checksumsRaw !== false) {
                $checksums = json_decode($checksumsRaw, true);
            }
            unset($checksumsRaw);
        }

        /**
         * @var $sorted FileAsset[]
         * @var $file   \Symfony\Component\Finder\SplFileInfo
         */
        foreach ($assets as $type) {
            $output->writeln(sprintf($this->messages['assets_type'], $type));
            $collection = [];
            $sorted     = [];
            $iterator   = $this->getIterator('*.' . $type, $this->source);
            $total      = iterator_count($iterator);
            if ($total === 0) {
                $output->writeln($this->messages['not_found']);
                continue;
            }
            $output->writeln(sprintf($this->messages['found'], $total));
            $a = 1;
            foreach ($iterator as $file) {
                $baseName           = $file->getBasename();
                $realPath           = $file->getRealPath();
                $hash               = md5_file($realPath);
                $key                = str_replace($this->source, '', $realPath);
                $options            = isset($this->conf[$key]) ? $this->conf[$key] : [];
                $priority           = isset($options['priority']) ? (int)$options['priority'] : null;
                $options['filters'] = isset($options['filters']) ? $options['filters'] : [];
                $filters            = [];
                $cached             = false;
                $cacheExpired       = !array_key_exists($realPath, $checksums) || $checksums[$realPath] != $hash;
                if (is_file($this->cache . $baseName) && $cacheEnabled && !$cacheExpired) {
                    /*if ($output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                        $output->writeln('Load from cache: ' . $realPath);
                    }*/
                    $path   = $this->cache . $baseName;
                    $cached = true;
                } else {
                    /*if ($output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                        $output->writeln('Load from source: ' . $realPath);
                    }*/
                    $path = $realPath;
                    if (is_array($options['filters'])) {
                        foreach ($options['filters'] as $filter) {
                            $filters[] = $this->getFilterByAlias($filter);
                        }
                    } elseif (!empty($options['filters'])) {
                        $filters[] = $this->getFilterByAlias($options['filters']);
                    }
                }
                $asset = new FileAsset($path, $filters);
                if (!$cached) {
                    // Write asset to cache
                    $filesystem->dumpFile($this->cache . $baseName, $asset->dump());
                }
                if ($output->getVerbosity() === OutputInterface::VERBOSITY_VERBOSE) {
                    $output->writeln(
                        sprintf(
                            $this->messages['found_asset'],
                            $a . '/' . $total,
                            $key,
                            $priority !== null ? $priority : 'unspecified'
                        )
                    );
                }
                if ($priority !== null) {
                    if (isset($sorted[$priority])) {
                        $sourceKey = str_replace($this->source, '', $sorted[$priority]->getSourceRoot())
                            . '/' . $sorted[$priority]->getSourcePath();
                        $output->writeln(
                            sprintf(
                                $this->messages['overwrite_asset'],
                                $sourceKey,
                                $key
                            )
                        );
                    }
                    $sorted[$priority] = $asset;
                } else {
                    $collection[] = $asset;
                }
                $checksumsNew[$realPath] = $hash;
                $a++;
            }
            ksort($sorted);
            $collection     = array_merge($sorted, $collection);
            $collection     = new AssetCollection($collection);
            $collectionPath = $this->public . $names[$type];
            $output->write(sprintf($this->messages['dump_assets'], $type, $collectionPath));
            $filesystem->dumpFile($collectionPath, $collection->dump());
            $filesystem->dumpFile($this->cache . 'checksums.json', json_encode($checksumsNew));
            if (is_file($collectionPath)) {
                $output->writeln($this->messages['dump_success']);
            } else {
                $output->writeln($this->messages['dump_failed']);
            }
            $i++;
        }
        $output->writeln($this->messages[$i === 0 ? 'failed' : 'success']);

        return 0;
    }

    /**
     * Returns iterator
     *
     * @param string $name      Name
     * @param string $directory Path to a directory
     *
     * @return Finder
     */
    private function getIterator($name, $directory)
    {
        return (new Finder())->files()->name($name)->in($directory);
    }

    /**
     * Returns a filter by an alias
     *
     * @param string $alias An alias
     *
     * @throws \LogicException Filter does not exists
     * @return FilterInterface
     */
    private function getFilterByAlias($alias)
    {
        if (isset($this->filters[$alias])) {
            return $this->filters[$alias];
        }
        $conf = $this->confFilters[$alias];
        switch ($alias) {
            case 'yui-js-compressor':
                $compressor = new JsCompressorFilter(
                    $this->confFilters['global']['yui-path'],
                    $this->confFilters['global']['java-path']
                );
                $compressor->setCharset($conf['charset']);
                $compressor->setLineBreak($conf['linebreak']);
                $compressor->setStackSize($conf['stack-size']);
                $compressor->setNomunge($conf['nomunge']);
                $compressor->setDisableOptimizations($conf['disable-optimization']);
                $compressor->setPreserveSemi($conf['preserve-semi']);
                $this->filters[$alias] = $compressor;
                break;
            case 'yui-css-compressor':
                $compressor = new CssCompressorFilter(
                    $this->confFilters['global']['yui-path'],
                    $this->confFilters['global']['java-path']
                );
                $compressor->setCharset($conf['charset']);
                $compressor->setLineBreak($conf['linebreak']);
                $compressor->setStackSize($conf['stack-size']);
                $this->filters[$alias] = $compressor;
                break;
            default:
                throw new \LogicException(sprintf('Filter "%s" does not exists', $alias));
        }

        return $this->filters[$alias];
    }

}
