<?php

/*
 * This file is part of the Stud.IP Experience API plugin.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Xabbuh\ExperienceApiPlugin\Storage\PdoMysql;

use Rhumsaa\Uuid\Uuid;
use Xabbuh\XApi\Model\Activity;
use Xabbuh\XApi\Model\Actor;
use Xabbuh\XApi\Model\Agent;
use Xabbuh\XApi\Model\Definition;
use Xabbuh\XApi\Model\Group;
use Xabbuh\XApi\Model\Statement;
use Xabbuh\XApi\Model\StatementReference;
use Xabbuh\XApi\Model\StatementsFilter;
use Xabbuh\XApi\Model\Verb;
use Xabbuh\XApi\Storage\Api\StatementManagerInterface;

/**
 * StatementManager implementation for MySQL based on the PHP PDO library.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
class StatementManager implements StatementManagerInterface
{
    /**
     * @var \PDO
     */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * {@inheritdoc}
     */
    public function findStatementById($statementId)
    {
        $stmt = $this->pdo->prepare(
            'SELECT
              s.uuid,
              s.actor_id,
              s.verb_id,
              s.object_id,
              s.object_type,
              a.group_id,
              a.type,
              a.name,
              a.mbox,
              a.mbox_sha1_sum,
              a.open_id,
              v.iri,
              v.display,
              o.activity_id,
              o.name AS definition_name,
              o.description AS definition_description,
              o.type AS definition_type,
              o.statement_id AS referenced_statement_id
            FROM
              xapi_statements AS s
            INNER JOIN
              xapi_actors AS a
            ON
              s.actor_id = a.id
            INNER JOIN
              xapi_verbs AS v
            ON
              s.verb_id = v.id
            LEFT JOIN
              xapi_objects AS o
            ON
              s.object_id = o.id
            WHERE
              s.uuid = :uuid'
        );
        $stmt->bindValue(':uuid', $statementId);
        $stmt->execute();

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ('agent' === $data['type']) {
            $actor = new Agent($data['mbox'], $data['mbox_sha1_sum'], $data['open_id'], null, $data['name']);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT
                  *
                FROM
                  xapi_actors
                WHERE
                  group_id = :group_id'
            );
            $stmt->bindValue(':group_id', $data['actor_id']);
            $stmt->execute();
            $members = array();

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $members[] = new Agent($row['mbox'], $row['mbox_sha1_sum'], $row['open_id'], null, $row['name']);
            }

            $actor = new Group($data['mbox'], $data['mbox_sha1_sum'], $data['open_id'], null, $data['name'], $members);
        }

        $verb = new Verb($data['iri'], unserialize($data['display']));

        $object = null;
        if ('activity' === $data['object_type']) {
            $definition = null;
            if (null !== $data['definition_name'] && null !== $data['definition_description']) {
                $definition = new Definition(
                    unserialize($data['definition_name']),
                    unserialize($data['definition_description']),
                    $data['definition_type']
                );
            }
            $object = new Activity($data['activity_id'], $definition);
        } elseif ('statement_reference' === $data['object_type']) {
            $object = new StatementReference($data['referenced_statement_id']);
        }

        return new Statement($data['uuid'], $actor, $verb, $object);
    }

    /**
     * {@inheritdoc}
     */
    public function findVoidedStatementById($voidedStatementId)
    {
        // TODO: Implement findVoidedStatementById() method.
    }

    /**
     * {@inheritdoc}
     */
    public function findStatementsBy(StatementsFilter $filter)
    {
        // TODO: Implement findStatementsBy() method.
    }

    /**
     * {@inheritdoc}
     */
    public function save(Statement $statement, $flush = true)
    {
        $actorId = null;
        $objectId = null;
        $objectType = null;
        $uuid = $statement->getId();

        if (null === $uuid) {
            $uuid = Uuid::uuid4()->toString();
        }

        // save the actor
        $actor = $statement->getActor();
        if ($actor instanceof Agent) {
            $actorId = $this->storeActor($statement->getActor(), 'agent');
        } elseif ($actor instanceof Group) {
            $actorId = $this->storeActor($actor, 'group');

            foreach ($actor->getMembers() as $agent) {
                $this->storeActor($agent, 'agent', $actorId);
            }
        }

        // save the verb
        $stmt = $this->pdo->prepare(
            'INSERT INTO
              xapi_verbs
            SET
              iri = :iri,
              display = :display'
        );
        $stmt->bindValue(':iri', $statement->getVerb()->getId());
        $stmt->bindValue(':display', serialize($statement->getVerb()->getDisplay()));
        $stmt->execute();
        $verbId = $this->pdo->lastInsertId();

        // save the object
        $object = $statement->getObject();
        if ($object instanceof Activity) {
            $definition = $object->getDefinition();
            $stmt = $this->pdo->prepare(
                'INSERT INTO
                  xapi_objects
                SET
                  activity_id = :activity_id,
                  name = :name,
                  description = :description,
                  type = :type'
            );
            $stmt->bindValue(':activity_id', $object->getId());
            $stmt->bindValue(':name', null !== $definition ? serialize($definition->getName()) : null);
            $stmt->bindValue(':description', null !== $definition ? serialize($definition->getDescription()) : null);
            $stmt->bindValue(':type', null !== $definition ? $definition->getType() : null);
            $stmt->execute();
            $objectId = $this->pdo->lastInsertId();
            $objectType = 'activity';
        } elseif ($object instanceof StatementReference) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO
                  xapi_objects
                SET
                  statement_id = :statement_id'
            );
            $stmt->bindValue(':statement_id', $object->getStatementId());
            $stmt->execute();
            $objectId = $this->pdo->lastInsertId();
            $objectType = 'statement_reference';
        }

        // save the statement itself
        $stmt = $this->pdo->prepare(
            'INSERT INTO
                xapi_statements
            SET
              uuid = :uuid,
              actor_id = :actor_id,
              verb_id = :verb_id,
              object_id = :object_id,
              object_type = :object_type'
        );
        $stmt->bindValue(':uuid', $uuid);
        $stmt->bindValue(':actor_id', $actorId);
        $stmt->bindValue(':verb_id', $verbId);
        $stmt->bindValue(':object_id', $objectId);
        $stmt->bindValue(':object_type', $objectType);
        $stmt->execute();

        return $uuid;
    }

    private function storeActor(Actor $actor, $type, $groupId = null)
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO
              xapi_actors
            SET
              group_id = :group_id,
              type = :type,
              name = :name,
              mbox = :mbox,
              mbox_sha1_sum = :mbox_sha1_sum,
              open_id = :open_id'
        );
        $stmt->bindValue(':group_id', $groupId);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':name', $actor->getName());
        $stmt->bindValue(':mbox', $actor->getMbox());
        $stmt->bindValue(':mbox_sha1_sum', $actor->getMboxSha1Sum());
        $stmt->bindValue(':open_id', $actor->getOpenId());
        $stmt->execute();

        return $this->pdo->lastInsertId();
    }
}
