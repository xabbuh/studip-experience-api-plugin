<?php

/*
 * This file is part of the Stud.IP Experience API plugin.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Xabbuh\ExperienceApiPlugin\Model\LearningRecordStore;
use Xabbuh\ExperienceApiPlugin\Storage\PdoMysql\StatementRepository;
use Xabbuh\XApi\Serializer\ActorSerializer;
use Xabbuh\XApi\Serializer\DocumentDataSerializer;
use Xabbuh\XApi\Serializer\Serializer;
use Xabbuh\XApi\Serializer\SerializerRegistry;
use Xabbuh\XApi\Serializer\StatementResultSerializer;
use Xabbuh\XApi\Serializer\StatementSerializer;

/**
 * REST API endpoints controllers.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * @property string $data
 */
class LrsController extends StudipController
{
    /**
     * @var SerializerRegistry
     */
    private $serializerRegistry;

    public function __construct($dispatcher)
    {
        parent::__construct($dispatcher);

        $serializer = Serializer::createSerializer();
        $this->serializerRegistry = new SerializerRegistry();
        $this->serializerRegistry->setStatementSerializer(new StatementSerializer($serializer));
        $this->serializerRegistry->setStatementResultSerializer(new StatementResultSerializer($serializer));
        $this->serializerRegistry->setActorSerializer(new ActorSerializer($serializer));
        $this->serializerRegistry->setDocumentDataSerializer(new DocumentDataSerializer($serializer));
    }

    public function statements_action($lrsId)
    {
        $lrs = new LearningRecordStore($lrsId);
        $statementRepository = new StatementRepository(DBManager::get(), $lrs);

        if ($lrs->isNew()) {
            $this->response->set_status(404);

            return;
        }

        $serializer = $this->serializerRegistry->getStatementSerializer();

        switch (strtoupper(Request::method())) {
            case 'PUT':
                break;
            case 'POST':
                $statement = $serializer->deserializeStatement($this->getRequestBody());
                $id = $statementRepository->storeStatement($statement);
                $this->data = json_encode(array($id));
                break;
            case 'GET':
                $statement = $statementRepository->findStatementById(Request::get('statementId'));

                if (null === $statement) {
                    $this->response->set_status(404);

                    return;
                }

                $this->data = $serializer->serializeStatement($statement);
                break;
            default:
        }
    }

    /**
     * {@inheritdoc}
     */
    public function extract_action_and_args($path)
    {
        if (preg_match('#^(\d+)/(\w+)#', $path, $matches)) {
            return array($matches[2], array($matches[1]));
        }

        return parent::extract_action_and_args($path);
    }

    private function getRequestBody()
    {
        return file_get_contents('php://input');
    }
}
