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

use Xabbuh\XApi\Model\Activity;
use Xabbuh\XApi\Model\Actor;
use Xabbuh\XApi\Model\Agent;
use Xabbuh\XApi\Model\Definition;
use Xabbuh\XApi\Model\Group;
use Xabbuh\XApi\Model\Result;
use Xabbuh\XApi\Model\Score;
use Xabbuh\XApi\Model\StatementReference;
use Xabbuh\XApi\Storage\Api\Mapping\MappedStatement;
use Xabbuh\XApi\Storage\Api\Mapping\MappedVerb;
use Xabbuh\XApi\Storage\Api\StatementRepository as BaseStatementRepository;

/**
 * StatementManager implementation for MySQL based on the PHP PDO library.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
class StatementRepository extends BaseStatementRepository
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
    protected function findMappedStatement(array $criteria)
    {
        $mappedStatements = $this->findMappedStatements($criteria);

        if (1 !== count($mappedStatements)) {
            return null;
        }

        return $mappedStatements[0];
    }

    /**
     * {@inheritdoc}
     */
    protected function findMappedStatements(array $criteria)
    {
        $stmt = $this->pdo->prepare(
            'SELECT
              s.uuid,
              s.actor_id,
              s.verb_id,
              s.object_id,
              s.object_type,
              s.has_result,
              s.scaled,
              s.raw,
              s.min,
              s.max,
              s.success,
              s.completion,
              s.response,
              s.duration,
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
        $stmt->bindValue(':uuid', $criteria['id']);
        $stmt->execute();

        $mappedStatements = array();

        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
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

            $mappedVerb = new MappedVerb();
            $mappedVerb->id = $data['iri'];
            $mappedVerb->display = unserialize($data['display']);

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

            $mappedStatement = new MappedStatement();
            $mappedStatement->id = $data['uuid'];
            $mappedStatement->actor = $actor;
            $mappedStatement->verb = $mappedVerb;
            $mappedStatement->object = $object;

            if (1 === (int) $data['has_result']) {
                $mappedStatement->result = new Result(
                    new Score($data['scaled'], $data['raw'], $data['min'], $data['max']),
                    1 === (int) $data['success'],
                    1 === (int) $data['completion'],
                    $data['response'],
                    $data['duration']
                );
            }

            $mappedStatements[] = $mappedStatement;
        }

        return $mappedStatements;
    }

    /**
     * {@inheritdoc}
     */
    protected function storeMappedStatement(MappedStatement $mappedStatement, $flush)
    {
        $actorId = null;
        $objectId = null;
        $objectType = null;

        // save the actor
        if ($mappedStatement->actor instanceof Agent) {
            $actorId = $this->storeActor($mappedStatement->actor, 'agent');
        } elseif ($mappedStatement->actor instanceof Group) {
            $actorId = $this->storeActor($mappedStatement->actor, 'group');

            foreach ($mappedStatement->actor->getMembers() as $agent) {
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
        $stmt->bindValue(':iri', $mappedStatement->verb->id);
        $stmt->bindValue(':display', serialize($mappedStatement->verb->display));
        $stmt->execute();
        $verbId = $this->pdo->lastInsertId();

        // save the object
        if ($mappedStatement->object instanceof Activity) {
            $definition = $mappedStatement->object->getDefinition();
            $stmt = $this->pdo->prepare(
                'INSERT INTO
                  xapi_objects
                SET
                  activity_id = :activity_id,
                  name = :name,
                  description = :description,
                  type = :type'
            );
            $stmt->bindValue(':activity_id', $mappedStatement->object->getId());
            $stmt->bindValue(':name', null !== $definition ? serialize($definition->getName()) : null);
            $stmt->bindValue(':description', null !== $definition ? serialize($definition->getDescription()) : null);
            $stmt->bindValue(':type', null !== $definition ? $definition->getType() : null);
            $stmt->execute();
            $objectId = $this->pdo->lastInsertId();
            $objectType = 'activity';
        } elseif ($mappedStatement->object instanceof StatementReference) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO
                  xapi_objects
                SET
                  statement_id = :statement_id'
            );
            $stmt->bindValue(':statement_id', $mappedStatement->object->getStatementId());
            $stmt->execute();
            $objectId = $this->pdo->lastInsertId();
            $objectType = 'statement_reference';
        }

        $result = $mappedStatement->result;

        // save the statement itself
        $stmt = $this->pdo->prepare(
            'INSERT INTO
                xapi_statements
            SET
              uuid = :uuid,
              actor_id = :actor_id,
              verb_id = :verb_id,
              object_id = :object_id,
              object_type = :object_type,
              has_result = :has_result,
              scaled = :scaled,
              raw = :raw,
              min = :min,
              max = :max,
              success = :success,
              completion = :completion,
              response = :response,
              duration = :duration'
        );
        $stmt->bindValue(':uuid', $mappedStatement->id);
        $stmt->bindValue(':actor_id', $actorId);
        $stmt->bindValue(':verb_id', $verbId);
        $stmt->bindValue(':object_id', $objectId);
        $stmt->bindValue(':object_type', $objectType);
        $stmt->bindValue(':has_result', null !== $result);
        $stmt->bindValue(':scaled', null !== $result ? $result->getScore()->getScaled() : null);
        $stmt->bindValue(':raw', null !== $result ? $result->getScore()->getRaw() : null);
        $stmt->bindValue(':min', null !== $result ? $result->getScore()->getMin() : null);
        $stmt->bindValue(':max', null !== $result ? $result->getScore()->getMax() : null);
        $stmt->bindValue(':success', null !== $result ? $result->getSuccess() : null);
        $stmt->bindValue(':completion', null !== $result ? $result->getCompletion() : null);
        $stmt->bindValue(':response', null !== $result ? $result->getResponse() : null);
        $stmt->bindValue(':duration', null !== $result ? $result->getDuration() : null);
        $stmt->execute();
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
