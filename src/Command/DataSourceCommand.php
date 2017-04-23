<?php

namespace Maghead\Command;

use CLIFramework\Command;

class DataSourceCommand extends BaseCommand
{
    public function brief()
    {
        return 'data source related commands.';
    }

    public function options($opts)
    {
        $opts->add('v|verbose', 'Display verbose information');
    }

    public function init()
    {
        $this->command('add');
        $this->command('remove');
    }

    public function execute()
    {
        $config = $this->getConfig(true);
        $dataSources = $config->getDataSources();
        foreach ($dataSources as $id => $config) {
            if ($this->options->verbose) {
                $this->logger->writeln(sprintf('%-10s %s', $id, $config['dsn']));
            } else {
                $this->logger->writeln($id);
            }
        }
    }
}
