<?php

/*
 * This file is part of the Stud.IP Experience API plugin.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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

    /**
     * @var StatementRepository
     */
    private $statementRepository;

    public function __construct($dispatcher)
    {
        parent::__construct($dispatcher);

        $serializer = Serializer::createSerializer();
        $this->serializerRegistry = new SerializerRegistry();
        $this->serializerRegistry->setStatementSerializer(new StatementSerializer($serializer));
        $this->serializerRegistry->setStatementResultSerializer(new StatementResultSerializer($serializer));
        $this->serializerRegistry->setActorSerializer(new ActorSerializer($serializer));
        $this->serializerRegistry->setDocumentDataSerializer(new DocumentDataSerializer($serializer));

        $this->statementRepository = new StatementRepository(DBManager::get());
    }

    public function statements_action()
    {
        $serializer = $this->serializerRegistry->getStatementSerializer();

        switch (strtoupper(Request::method())) {
            case 'PUT':
                break;
            case 'POST':
                $statement = $serializer->deserializeStatement($this->getRequestBody());
                $id = $this->statementRepository->storeStatement($statement);
                $this->data = json_encode(array($id));
                break;
            case 'GET':
                $statement = $this->statementRepository->findStatementById(Request::get('statementId'));

                if (null === $statement) {
                    $this->response->set_status(404);

                    return;
                }

                $this->data = $serializer->serializeStatement($statement);
                break;
            default:
        }
    }

    private function getRequestBody()
    {
        return file_get_contents('php://input');
    }
}
