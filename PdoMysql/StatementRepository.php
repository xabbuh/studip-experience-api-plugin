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

use Xabbuh\ExperienceApiPlugin\Model\LearningRecordStore;
use Xabbuh\XApi\Model\Account;
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

    /**
     * @var LearningRecordStore
     */
    private $learningRecordStore;

    public function __construct(\PDO $pdo, LearningRecordStore $learningRecordStore)
    {
        $this->pdo = $pdo;
        $this->learningRecordStore = $learningRecordStore;
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
              s.verb_iri,
              s.verb_display,
              s.object_type,
              s.activity_id,
              s.activity_name,
              s.activity_description,
              s.activity_type,
              s.referenced_statement_id,
              s.has_result,
              s.scaled,
              s.raw,
              s.min,
              s.max,
              s.success,
              s.completion,
              s.response,
              s.duration,
              s.authority_id,
              actor.type AS actor_type,
              actor.name AS actor_name,
              actor.mbox AS actor_mbox,
              actor.mbox_sha1_sum AS actor_mbox_sha1_sum,
              actor.open_id AS actor_open_id,
              actor.has_account AS actor_has_account,
              actor.account_name AS actor_account_name,
              actor.account_home_page AS actor_account_home_page,
              authority.type AS authority_type,
              authority.name AS authority_name,
              authority.mbox AS authority_mbox,
              authority.mbox_sha1_sum AS authority_mbox_sha1_sum,
              authority.open_id AS authority_open_id,
              authority.has_account AS authority_has_account,
              authority.account_name AS authority_account_name,
              authority.account_home_page AS authority_account_home_page
            FROM
              xapi_statements AS s
            INNER JOIN
              xapi_actors AS actor
            ON
              s.actor_id = actor.id
            LEFT JOIN
              xapi_actors AS authority
            ON
              s.authority_id = authority.id
            WHERE
              s.uuid = :uuid'
        );
        $stmt->bindValue(':uuid', $criteria['id']);
        $stmt->execute();

        $mappedStatements = array();

        while ($data = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $actorAccount = null;

            $actor = $this->buildActor(array(
                'actor_id' => $data['actor_id'],
                'type' => $data['actor_type'],
                'name' => $data['actor_name'],
                'mbox' => $data['actor_mbox'],
                'mbox_sha1_sum' => $data['actor_mbox_sha1_sum'],
                'open_id' => $data['actor_open_id'],
                'has_account' => $data['actor_has_account'],
                'account_name' => $data['actor_account_name'],
                'account_home_page' => $data['actor_account_home_page'],
            ));

            $mappedVerb = new MappedVerb();
            $mappedVerb->id = $data['verb_iri'];
            $mappedVerb->display = unserialize($data['verb_display']);

            if ('activity' === $data['object_type']) {
                $definition = null;
                if (null !== $data['activity_name'] && null !== $data['activity_description']) {
                    $definition = new Definition(
                        unserialize($data['activity_name']),
                        unserialize($data['activity_description']),
                        $data['activity_type']
                    );
                }
                $object = new Activity($data['activity_id'], $definition);
            } elseif ('statement_reference' === $data['object_type']) {
                $object = new StatementReference($data['referenced_statement_id']);
            } else {
                $object = null;
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

            if (null !== $data['authority_id']) {
                $mappedStatement->authority = $this->buildActor(array(
                    'actor_id' => $data['authority_id'],
                    'type' => $data['authority_type'],
                    'name' => $data['authority_name'],
                    'mbox' => $data['authority_mbox'],
                    'mbox_sha1_sum' => $data['authority_mbox_sha1_sum'],
                    'open_id' => $data['authority_open_id'],
                    'has_account' => $data['authority_has_account'],
                    'account_name' => $data['authority_account_name'],
                    'account_home_page' => $data['authority_account_home_page'],
                ));
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
        $actorId = $this->storeActor($mappedStatement->actor);

        $result = $mappedStatement->result;
        $activityId = null;
        $activityName = null;
        $activityDescription = null;
        $activityType = null;
        $referencedStatementId = null;
        $authorityId = null;

        if (null !== $mappedStatement->authority) {
            $authorityId = $this->storeActor($mappedStatement->authority);
        }

        // save the statement itself
        $stmt = $this->pdo->prepare(
            'INSERT INTO
                xapi_statements
            SET
              uuid = :uuid,
              lrs_id = :lrs_id,
              actor_id = :actor_id,
              verb_iri = :verb_iri,
              verb_display = :verb_display,
              object_type = :object_type,
              activity_id = :activity_id,
              activity_name = :activity_name,
              activity_description = :activity_description,
              activity_type = :activity_type,
              referenced_statement_id = :referenced_statement_id,
              has_result = :has_result,
              scaled = :scaled,
              raw = :raw,
              min = :min,
              max = :max,
              success = :success,
              completion = :completion,
              response = :response,
              duration = :duration,
              authority_id = :authority_id'
        );
        $stmt->bindValue(':uuid', $mappedStatement->id);
        $stmt->bindValue(':lrs_id', $this->learningRecordStore->getId());
        $stmt->bindValue(':actor_id', $actorId);
        $stmt->bindValue(':verb_iri', $mappedStatement->verb->id);
        $stmt->bindValue(':verb_display', serialize($mappedStatement->verb->display));

        if ($mappedStatement->object instanceof Activity) {
            $definition = $mappedStatement->object->getDefinition();

            $objectType = 'activity';
            $activityId = $mappedStatement->object->getId();

            if (null !== $definition) {
                $activityName = serialize($definition->getName());
                $activityDescription = serialize($definition->getDescription());
                $activityType = $definition->getType();
            }
        } elseif ($mappedStatement->object instanceof StatementReference) {
            $objectType = 'statement_reference';
            $referencedStatementId = $mappedStatement->object->getStatementId();
        }

        $stmt->bindValue(':object_type', $objectType);
        $stmt->bindValue(':activity_id', $activityId);
        $stmt->bindValue(':activity_name', $activityName);
        $stmt->bindValue(':activity_description', $activityDescription);
        $stmt->bindValue(':activity_type', $activityType);
        $stmt->bindValue(':referenced_statement_id', $referencedStatementId);
        $stmt->bindValue(':has_result', null !== $result);
        $stmt->bindValue(':scaled', null !== $result ? $result->getScore()->getScaled() : null);
        $stmt->bindValue(':raw', null !== $result ? $result->getScore()->getRaw() : null);
        $stmt->bindValue(':min', null !== $result ? $result->getScore()->getMin() : null);
        $stmt->bindValue(':max', null !== $result ? $result->getScore()->getMax() : null);
        $stmt->bindValue(':success', null !== $result ? $result->getSuccess() : null);
        $stmt->bindValue(':completion', null !== $result ? $result->getCompletion() : null);
        $stmt->bindValue(':response', null !== $result ? $result->getResponse() : null);
        $stmt->bindValue(':duration', null !== $result ? $result->getDuration() : null);
        $stmt->bindValue(':authority_id', $authorityId);
        $stmt->execute();
    }

    private function buildActor(array $data)
    {
        $actorAccount = null;

        if (1 === (int) $data['has_account']) {
            $actorAccount = new Account($data['account_name'], $data['account_home_page']);
        }

        if ('agent' === $data['type']) {
            return new Agent($data['mbox'], $data['mbox_sha1_sum'], $data['open_id'], $actorAccount, $data['name']);
        }

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
            $memberAccount = null;

            if (1 === (int) $row['has_account']) {
                $memberAccount = new Account($row['account_name'], $row['account_home_page']);
            }

            $members[] = new Agent($row['mbox'], $row['mbox_sha1_sum'], $row['open_id'], $memberAccount, $row['name']);
        }

        return new Group($data['mbox'], $data['mbox_sha1_sum'], $data['open_id'], $actorAccount, $data['name'], $members);
    }

    private function storeActor(Actor $actor)
    {
        if ($actor instanceof Group) {
            $actorId = $this->persistActor($actor, 'group');

            foreach ($actor->getMembers() as $agent) {
                $this->persistActor($agent, 'agent', $actorId);
            }

            return $actorId;
        }

        return $this->persistActor($actor, 'agent');
    }

    private function persistActor(Actor $actor, $type, $groupId = null)
    {
        $account = $actor->getAccount();

        $stmt = $this->pdo->prepare(
            'INSERT INTO
              xapi_actors
            SET
              group_id = :group_id,
              type = :type,
              name = :name,
              mbox = :mbox,
              mbox_sha1_sum = :mbox_sha1_sum,
              open_id = :open_id,
              has_account = :has_account,
              account_name = :account_name,
              account_home_page = :account_home_page'
        );
        $stmt->bindValue(':group_id', $groupId);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':name', $actor->getName());
        $stmt->bindValue(':mbox', $actor->getMbox());
        $stmt->bindValue(':mbox_sha1_sum', $actor->getMboxSha1Sum());
        $stmt->bindValue(':open_id', $actor->getOpenId());
        $stmt->bindValue(':has_account', null !== $account);
        $stmt->bindValue(':account_name', null !== $account ? $account->getName() : null);
        $stmt->bindValue(':account_home_page', null !== $account ? $account->getHomePage() : null);
        $stmt->execute();

        return $this->pdo->lastInsertId();
    }
}
