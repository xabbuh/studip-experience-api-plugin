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
use Xabbuh\ExperienceApiPlugin\Model\LearningRecordStore;
use Xabbuh\XApi\Common\Exception\NotFoundException;
use Xabbuh\XApi\Model\Account;
use Xabbuh\XApi\Model\Activity;
use Xabbuh\XApi\Model\Actor;
use Xabbuh\XApi\Model\Agent;
use Xabbuh\XApi\Model\Definition;
use Xabbuh\XApi\Model\Group;
use Xabbuh\XApi\Model\InverseFunctionalIdentifier;
use Xabbuh\XApi\Model\IRI;
use Xabbuh\XApi\Model\IRL;
use Xabbuh\XApi\Model\LanguageMap;
use Xabbuh\XApi\Model\Result;
use Xabbuh\XApi\Model\Score;
use Xabbuh\XApi\Model\Statement;
use Xabbuh\XApi\Model\StatementId;
use Xabbuh\XApi\Model\StatementReference;
use Xabbuh\XApi\Model\StatementsFilter;
use Xabbuh\XApi\Model\Verb;
use XApi\Repository\Api\StatementRepositoryInterface;

/**
 * StatementManager implementation for MySQL based on the PHP PDO library.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
final class StatementRepository implements StatementRepositoryInterface
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

    public function findStatementById(StatementId $statementId, Actor $authority = null)
    {
        $criteria = array(
            'id' => $statementId->getValue(),
        );
        $statements = $this->findStatements($criteria);

        if (1 !== count($statements)) {
            throw new NotFoundException(sprintf('A statement with id "%s" could not be found.', $statementId->getValue()));
        }

        $statement = $statements[0];

        if ($statement->isVoidStatement()) {
            throw new NotFoundException(sprintf('The stored statement with id "%s" is a voiding statement.', $statementId->getValue()));
        }

        return $statement;
    }

    public function findVoidedStatementById(StatementId $voidedStatementId, Actor $authority = null)
    {
        $criteria = array(
            'id' => $voidedStatementId->getValue(),
        );
        $statements = $this->findStatements($criteria);

        if (1 !== count($statements)) {
            throw new NotFoundException(sprintf('A voided statement with id "%s" could not be found.', $voidedStatementId->getValue()));
        }

        $statement = $statements[0];

        if (!$statement->isVoidStatement()) {
            throw new NotFoundException(sprintf('The stored statement with id "%s" is no voiding statement.', $voidedStatementId->getValue()));
        }

        return $statement;
    }

    public function findStatementsBy(StatementsFilter $filter, Actor $authority = null)
    {
        $criteria = $filter->getFilter();

        if (null !== $authority) {
            $criteria['authority'] = $authority;
        }

        return $this->findStatements($criteria);
    }

    public function storeStatement(Statement $statement, $flush = true)
    {
        if (null === $statement->getId()) {
            $statement = $statement->withId(StatementId::fromUuid(Uuid::uuid4()));
        }

        $actorId = null;
        $objectId = null;
        $objectType = null;

        // save the actor
        $actorId = $this->storeActor($statement->getActor());

        $verbDisplay = null;
        $object = $statement->getObject();
        $result = $statement->getResult();
        $activityId = null;
        $activityName = null;
        $activityDescription = null;
        $activityType = null;
        $referencedStatementId = null;
        $authorityId = null;

        if (null !== $display = $statement->getVerb()->getDisplay()) {
            foreach ($display->languageTags() as $languageTag) {
                $verbDisplay[$languageTag] = $display[$languageTag];
            }
        }

        if (null !== $authority = $statement->getAuthority()) {
            $authorityId = $this->storeActor($authority);
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
        $stmt->bindValue(':uuid', $statement->getId()->getValue());
        $stmt->bindValue(':lrs_id', $this->learningRecordStore->getId());
        $stmt->bindValue(':actor_id', $actorId);
        $stmt->bindValue(':verb_iri', $statement->getVerb()->getId()->getValue());
        $stmt->bindValue(':verb_display', serialize($verbDisplay));

        if ($object instanceof Activity) {
            $definition = $object->getDefinition();

            $objectType = 'activity';
            $activityId = $object->getId();

            if (null !== $definition) {
                $activityName = serialize($definition->getName());
                $activityDescription = serialize($definition->getDescription());
                $activityType = $definition->getType();
            }
        } elseif ($object instanceof StatementReference) {
            $objectType = 'statement_reference';
            $referencedStatementId = $object->getStatementId();
        }

        $stmt->bindValue(':object_type', $objectType);
        $stmt->bindValue(':activity_id', null !== $activityId ? $activityId->getValue() : null);
        $stmt->bindValue(':activity_name', $activityName);
        $stmt->bindValue(':activity_description', $activityDescription);
        $stmt->bindValue(':activity_type', $activityType);
        $stmt->bindValue(':referenced_statement_id', null !== $referencedStatementId ? $referencedStatementId->getValue() : null);
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

        return $statement->getId();
    }

    /**
     * @param array $criteria
     *
     * @return Statement[]
     */
    private function findStatements(array $criteria)
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

        $statements = array();

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

            $display = null;

            if (null !== $data['verb_display']) {
                $display = LanguageMap::create(unserialize($data['verb_display']));
            }

            $verb = new Verb(IRI::fromString($data['verb_iri']), $display);

            if ('activity' === $data['object_type']) {
                $definition = null;
                if (null !== $data['activity_name'] && null !== $data['activity_description']) {
                    $definition = new Definition(
                        unserialize($data['activity_name']),
                        unserialize($data['activity_description']),
                        $data['activity_type']
                    );
                }
                $object = new Activity(IRI::fromString($data['activity_id']), $definition);
            } elseif ('statement_reference' === $data['object_type']) {
                $object = new StatementReference(StatementId::fromString($data['referenced_statement_id']));
            } else {
                $object = null;
            }

            $statement = new Statement(StatementId::fromString($data['uuid']), $actor, $verb, $object);

            if (1 === (int) $data['has_result']) {
                $statement = $statement->withResult(new Result(
                    new Score(
                        null !== $data['scaled'] ? (float) $data['scaled'] : null,
                        null !== $data['raw'] ? (float) $data['raw'] : null,
                        null !== $data['min'] ? (float) $data['min'] : null,
                        null !== $data['max'] ? (float) $data['max'] : null
                    ),
                    null !== $data['success'] ? 1 === (int) $data['success'] : null,
                    null !== $data['completion'] ? 1 === (int) $data['completion'] : null,
                    $data['response'],
                    $data['duration']
                ));
            }

            if (null !== $data['authority_id']) {
                $statement = $statement->withAuthority($this->buildActor(array(
                    'actor_id' => $data['authority_id'],
                    'type' => $data['authority_type'],
                    'name' => $data['authority_name'],
                    'mbox' => $data['authority_mbox'],
                    'mbox_sha1_sum' => $data['authority_mbox_sha1_sum'],
                    'open_id' => $data['authority_open_id'],
                    'has_account' => $data['authority_has_account'],
                    'account_name' => $data['authority_account_name'],
                    'account_home_page' => $data['authority_account_home_page'],
                )));
            }

            $statements[] = $statement;
        }

        return $statements;
    }

    private function buildActor(array $data)
    {
        $actorAccount = null;

        if (1 === (int) $data['has_account']) {
            $actorAccount = new Account($data['account_name'], IRL::fromString($data['account_home_page']));
        }

        if ('agent' === $data['type']) {
            return new Agent($this->buildInverseFunctionalIdentifier($data, $actorAccount), $data['name']);
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
                $memberAccount = new Account($row['account_name'], IRL::fromString($row['account_home_page']));
            }

            $members[] = new Agent($this->buildInverseFunctionalIdentifier($row, $memberAccount), $row['name']);
        }

        return new Group($this->buildInverseFunctionalIdentifier($data), $data['name'], $members);
    }

    private function buildInverseFunctionalIdentifier(array $data, Account $account = null)
    {
        $iri = null;

        if (null !== $data['mbox']) {
            $iri = InverseFunctionalIdentifier::withMbox(IRI::fromString($data['mbox']));
        }

        if (null !== $data['mbox_sha1_sum']) {
            $iri = InverseFunctionalIdentifier::withMboxSha1Sum($data['mbox_sha1_sum']);
        }

        if (null !== $data['open_id']) {
            $iri = InverseFunctionalIdentifier::withOpenId($data['open_id']);
        }

        if (null !== $account) {
            $iri = InverseFunctionalIdentifier::withAccount($account);
        }

        return $iri;
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
        $iri = $actor->getInverseFunctionalIdentifier();
        $account = $iri->getAccount();

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
        $stmt->bindValue(':mbox', null !== $iri->getMbox() ? $iri->getMbox()->getValue() : null);
        $stmt->bindValue(':mbox_sha1_sum', $iri->getMboxSha1Sum());
        $stmt->bindValue(':open_id', $iri->getOpenId());
        $stmt->bindValue(':has_account', null !== $account);
        $stmt->bindValue(':account_name', null !== $account ? $account->getName() : null);
        $stmt->bindValue(':account_home_page', null !== $account ? $account->getHomePage()->getValue() : null);
        $stmt->execute();

        return $this->pdo->lastInsertId();
    }
}
