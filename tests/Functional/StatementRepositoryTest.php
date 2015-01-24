<?php

/*
 * This file is part of the Stud.IP Experience API plugin.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Xabbuh\ExperienceApiPlugin\Tests\Functional;

use Xabbuh\ExperienceApiPlugin\Storage\PdoMysql\StatementRepository;
use Xabbuh\XApi\Storage\Api\Test\Functional\StatementRepositoryTest as BaseStatementRepositoryTest;

/**
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
class StatementRepositoryTest extends BaseStatementRepositoryTest
{
    /**
     * @var \PDO
     */
    private $pdo;

    protected function createStatementRepository()
    {
        $host = $_ENV['mysql_host'];
        $port = $_ENV['mysql_port'];
        $database = $_ENV['mysql_database'];
        $user = $_ENV['mysql_user'];
        $password = $_ENV['mysql_password'];
        $dsn = sprintf('mysql:dbname=%s;host=%s;port=%d',$database, $host, $port);
        $this->pdo = new \PDO($dsn, $user, $password);

        return new StatementRepository($this->pdo);
    }

    protected function cleanDatabase()
    {
        $this->pdo->exec('TRUNCATE xapi_actors');
        $this->pdo->exec('TRUNCATE xapi_objects');
        $this->pdo->exec('TRUNCATE xapi_statements');
        $this->pdo->exec('TRUNCATE xapi_verbs');
    }
}
