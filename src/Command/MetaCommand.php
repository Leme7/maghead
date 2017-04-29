<?php

namespace Maghead\Command;

use Maghead\Manager\MetadataManager;

class MetaCommand extends BaseCommand
{
    public function brief()
    {
        return 'Set, get or list meta.';
    }

    public function usage()
    {
        return
              "\tmaghead meta\n"
            ."\tmaghead meta [key] [value]\n"
            ."\tmaghead meta [key]\n";
    }

    public function execute()
    {
        $dsId = $this->getCurrentDataSourceId();
        $queryDriver = $this->getCurrentQueryDriver();
        $conn = $this->getCurrentConnection();

        $args = func_get_args();
        if (empty($args)) {
            $meta = new MetadataManager($conn, $queryDriver);
            printf("%26s | %-20s\n", 'Key', 'Value');
            printf("%s\n", str_repeat('=', 50));
            foreach ($meta as $key => $value) {
                printf("%26s   %-20s\n", $key, $value);
            }
        } elseif (count($args) == 1) {
            $key = $args[0];
            $meta = new MetadataManager($conn, $queryDriver);
            $value = $meta[$key];
            $this->logger->info("$key = $value");
        } elseif (count($args) == 2) {
            list($key, $value) = $args;
            $this->logger->info("Setting meta $key to $value.");
            $meta = new MetadataManager($conn, $queryDriver);
            $meta[$key] = $value;
        }
    }
}
