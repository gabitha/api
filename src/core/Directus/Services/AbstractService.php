<?php

namespace Directus\Services;

use Directus\Application\Container;
use Directus\Config\Config;
use Directus\Database\Exception\ForbiddenSystemTableDirectAccessException;
use Directus\Database\Exception\ItemNotFoundException;
use Directus\Database\RowGateway\BaseRowGateway;
use Directus\Database\Schema\DataTypes;
use Directus\Database\Schema\SchemaManager;
use Directus\Database\TableGateway\RelationalTableGateway;
use Directus\Database\TableGatewayFactory;
use Directus\Exception\ForbiddenException;
use Directus\Exception\UnprocessableEntityException;
use Directus\Hook\Emitter;
use Directus\Hook\Payload;
use Directus\Permissions\Acl;
use Directus\Util\ArrayUtils;
use Directus\Validator\Exception\InvalidRequestException;
use Directus\Validator\Validator;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\ConstraintViolationList;

abstract class AbstractService
{
    /**
     * @var Container
     */
    protected $container;

    /**
     * @var Validator
     */
    protected $validator;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->validator = new Validator();
    }

    /**
     * Gets application container
     *
     * @return Container
     */
    protected function getContainer()
    {
        return $this->container;
    }

    /**
     * Gets application db connection instance
     *
     * @return \Zend\Db\Adapter\Adapter
     */
    protected function getConnection()
    {
        return $this->getContainer()->get('database');
    }

    /**
     * Gets schema manager instance
     *
     * @return SchemaManager
     */
    public function getSchemaManager()
    {
        return $this->getContainer()->get('schema_manager');
    }

    /**
     * @param $name
     * @param $acl
     *
     * @return RelationalTableGateway
     */
    public function createTableGateway($name, $acl = true)
    {
        return TableGatewayFactory::create($name, [
            'acl' => $acl !== false ? $this->getAcl() : false,
            'connection' => $this->getConnection()
        ]);
    }

    /**
     * Gets Acl instance
     *
     * @return Acl
     */
    protected function getAcl()
    {
        return $this->getContainer()->get('acl');
    }

    /**
     * Validates a given data against a constraint
     *
     * @param array $data
     * @param array $constraints
     *
     * @throws UnprocessableEntityException
     */
    public function validate(array $data, array $constraints)
    {
        $constraintViolations = $this->getViolations($data, $constraints);

        $this->throwErrorIfAny($constraintViolations);
    }

    /**
     *
     *
     * @param string $name
     *
     * @throws ForbiddenSystemTableDirectAccessException
     */
    public function throwErrorIfSystemTable($name)
    {
        /** @var SchemaManager $schemaManager */
        $schemaManager = $this->container->get('schema_manager');
        if (in_array($name, $schemaManager->getSystemCollections())) {
            throw new ForbiddenSystemTableDirectAccessException($name);
        }
    }

    /**
     * @param array $data
     * @param array $constraints
     *
     * @return array
     */
    protected function getViolations(array $data, array $constraints)
    {
        $violations = [];

        foreach ($constraints as $field => $constraint) {
            if (is_string($constraint)) {
                $constraint = explode('|', $constraint);
            }

            $violations[$field] = $this->validator->validate(ArrayUtils::get($data, $field), $constraint);
        }

        return $violations;
    }

    /**
     * Throws an exception if any violations was made
     *
     * @param ConstraintViolationList[] $violations
     *
     * @throws UnprocessableEntityException
     */
    protected function throwErrorIfAny(array $violations)
    {
        $results = [];

        /** @var ConstraintViolationList $violation */
        foreach ($violations as $field => $violation) {
            $iterator = $violation->getIterator();

            $errors = [];
            while ($iterator->valid()) {
                $constraintViolation = $iterator->current();
                $errors[] = $constraintViolation->getMessage();
                $iterator->next();
            }

            if ($errors) {
                $results[] = sprintf('%s: %s', $field, implode(', ', $errors));
            }
        }

        if (count($results) > 0) {
            throw new InvalidRequestException(implode(' ', $results));
        }
    }

    /**
     * Creates the constraint for a an specific table columns
     *
     * @param string $collectionName
     * @param array $fields List of columns name
     *
     * @return array
     */
    protected function createConstraintFor($collectionName, array $fields = [])
    {
        /** @var SchemaManager $schemaManager */
        $schemaManager = $this->container->get('schema_manager');
        $collectionObject = $schemaManager->getCollection($collectionName);

        $constraints = [];

        if ($fields === null) {
            return $constraints;
        }

        foreach ($collectionObject->getFields($fields) as $field) {
            $columnConstraints = [];

            if ($field->hasAutoIncrement()) {
                continue;
            }

            $isRequired = $field->isRequired();
            $isStatusField = $field->isStatusType();
            if (!$isRequired && $isStatusField && $field->getDefaultValue() === null) {
                $isRequired = true;
            }

            if ($isRequired || (!$field->isNullable() && $field->getDefaultValue() == null)) {
                $columnConstraints[] = 'required';
            }

            if (DataTypes::isArray($field->getType())) {
                $columnConstraints[] = 'array';
            } else if (DataTypes::isJson($field->getType())) {
                $columnConstraints[] = 'json';
            }
            // TODO: Relational accept its type, null (if allowed) and a object
            // else if ($schemaManager->isNumericType($field->getType())) {
            //     $columnConstraints[] = 'numeric';
            // } else if ($schemaManager->isStringType($field->getType())) {
            //     $columnConstraints[] = 'string';
            // }

            if (!empty($columnConstraints)) {
                $constraints[$field->getName()] = $columnConstraints;
            }
        }

        return $constraints;
    }

    protected function tagResponseCache($tags)
    {
        $this->container->get('response_cache')->tag($tags);
    }

    protected function invalidateCacheTags($tags)
    {
        $this->container->get('cache')->getPool()->invalidateTags($tags);
    }

    /**
     * @param RelationalTableGateway $gateway
     * @param array $params
     * @param \Closure|null $queryCallback
     *
     * @return array|mixed
     */
    protected function getItemsAndSetResponseCacheTags(RelationalTableGateway $gateway, array $params, \Closure $queryCallback = null)
    {
        return $this->getDataAndSetResponseCacheTags([$gateway, 'getItems'], [$params, $queryCallback]);
    }

    /**
     * @param RelationalTableGateway $gateway
     * @param string|int|array
     * @param array $params
     *
     * @return array|mixed
     */
    protected function getItemsByIdsAndSetResponseCacheTags(RelationalTableGateway $gateway, $ids, array $params)
    {
        return $this->getDataAndSetResponseCacheTags([$gateway, 'getItemsByIds'], [$ids, $params]);
    }

    /**
     * @param callable $callable
     * @param array $callableParams
     * @param null $pkName
     * @return array|mixed
     */
    protected function getDataAndSetResponseCacheTags(Callable $callable, array $callableParams = [], $pkName = null)
    {
        $container = $this->container;

        if (is_array($callable) && $callable[0] instanceof RelationalTableGateway) {
            /** @var $callable[0] RelationalTableGateway */
            $pkName = $callable[0]->primaryKeyFieldName;
        }

        $setIdTags = function(Payload $payload) use($pkName, $container) {
            $collectionName = $payload->attribute('collection_name');

            $this->tagResponseCache('table_'.$collectionName);
            // Note: See other reference to permissions_collection_<>
            // to proper set a new tag now that group doesn't exist anymore
            $this->tagResponseCache('permissions_collection_'.$collectionName);

            foreach ($payload->getData() as $item) {
                $this->tagResponseCache('entity_'.$collectionName.'_'.$item[$pkName]);
            }

            return $payload;
        };

        /** @var Emitter $hookEmitter */
        $hookEmitter = $container->get('hook_emitter');
        /** @var Config $config */
        $config = $container->get('config');
        $cacheEnabled = $config->get('cache.enabled') === true;

        $listenerId = null;
        if ($cacheEnabled) {
            $listenerId = $hookEmitter->addFilter('collection.select', $setIdTags, Emitter::P_LOW);
        }

        $result = call_user_func_array($callable, $callableParams);

        if ($cacheEnabled & $listenerId) {
            $hookEmitter->removeListenerWithIndex($listenerId);
        }

        return $result;
    }

    protected function getCRUDParams(array $params)
    {
        $activityLoggingDisabled = ArrayUtils::get($params, 'activity_skip', 0) == 1;
        $activityMode = $activityLoggingDisabled
                        ? RelationalTableGateway::ACTIVITY_ENTRY_MODE_DISABLED
                        : RelationalTableGateway::ACTIVITY_ENTRY_MODE_PARENT;

        return [
            'activity_mode' => $activityMode,
            'activity_comment' => ArrayUtils::get($params, 'comment')
        ];
    }

    /**
     * Validates the payload against a collection fields
     *
     * @param string $collectionName
     * @param array|null $fields
     * @param array $payload
     * @param array $params
     *
     * @throws UnprocessableEntityException
     */
    protected function validatePayload($collectionName, $fields, array $payload, array $params)
    {
        $columnsToValidate = [];

        // TODO: Validate empty request
        // If the user PATCH, POST or PUT with empty body, must throw an exception to avoid continue the execution
        // with the exception of POST, that can use the default value instead
        // TODO: Crate a email interface for the sake of validation
        if (is_array($fields)) {
            $columnsToValidate = $fields;
        }

        $this->validatePayloadFields($collectionName, $payload);
        // TODO: Ideally this should be part of the validator constraints
        // we need to accept options for the constraint builder
        $this->validatePayloadWithFieldsValidation($collectionName, $payload);

        $this->validate($payload, $this->createConstraintFor($collectionName, $columnsToValidate));
    }

    /**
     * Verify that the payload has its primary key otherwise an exception will be thrown
     *
     * @param $collectionName
     * @param array $payload
     *
     * @throws UnprocessableEntityException
     */
    protected function validatePayloadHasPrimaryKey($collectionName, array $payload)
    {
        $collection = $this->getSchemaManager()->getCollection($collectionName);
        $primaryKey = $collection->getPrimaryKeyName();

        if (!ArrayUtils::has($payload, $primaryKey) || !$payload[$primaryKey]) {
            throw new UnprocessableEntityException('Payload must include the primary key');
        }
    }

    /**
     * Throws an exception when the payload has one or more unknown fields
     *
     * @param string $collectionName
     * @param array $payload
     *
     * @throws UnprocessableEntityException
     */
    protected function validatePayloadFields($collectionName, array $payload)
    {
        $collection = $this->getSchemaManager()->getCollection($collectionName);
        $unknownFields = [];

        foreach (array_keys($payload) as $fieldName) {
            if (!$collection->hasField($fieldName)) {
                $unknownFields[] = $fieldName;
            }
        }

        $unknownFields = array_diff($unknownFields, $this->unknownFieldsAllowed());

        if (!empty($unknownFields)) {
            throw new UnprocessableEntityException(
                sprintf('Payload fields: "%s" does not exist in "%s" collection.', implode(', ', $unknownFields), $collectionName)
            );
        }
    }

    /**
     * Throws an exception when one or more fields in the payload fails its field validation
     *
     * @param string $collectionName
     * @param array $payload
     *
     * @throws UnprocessableEntityException
     */
    protected function validatePayloadWithFieldsValidation($collectionName, array $payload)
    {
        $collection = $this->getSchemaManager()->getCollection($collectionName);
        $violations = [];

        foreach ($payload as $fieldName => $value) {
            $field = $collection->getField($fieldName);
            if (!$field) {
                continue;
            }

            if ($validation = $field->getValidation()) {
                if (!\Directus\is_valid_regex_pattern($validation)) {
                    throw new UnprocessableEntityException(
                        sprintf('Field "%s": "%s" is an invalid regular expression', $fieldName, $validation)
                    );
                }

                $violations[$fieldName] = $this->validator->getProvider()->validate($value, [
                    new Regex($validation)
                ]);
            }
        }

        $this->throwErrorIfAny($violations);
    }

    /**
     * @param string $collection
     * @param array $payload
     * @param array $params
     *
     * @throws ForbiddenException
     */
    protected function enforcePermissions($collection, array $payload, array $params)
    {
        $collectionObject = $this->getSchemaManager()->getCollection($collection);
        $status = null;
        $statusField = $collectionObject->getStatusField();
        if ($statusField) {
            $status = ArrayUtils::get($payload, $statusField->getName(), $statusField->getDefaultValue());
        }

        $acl = $this->getAcl();
        $requireExplanation = $acl->requireExplanation($collection, $status);
        $action = ArrayUtils::get($params, 'action');
        if (!$requireExplanation && $action) {
            $requireExplanation = $acl->requireExplanationAt($action, $collection, $status);
        }

        if ($requireExplanation && empty($params['comment'])) {
            throw new ForbiddenException('Activity comment required for collection: ' . $collection);
        }

        // Enforce write field blacklist
        $this->getAcl()->enforceWriteField($collection, array_keys($payload), $status);
    }

    protected function enforceCreatePermissions($collection, array $payload, array $params)
    {
        $this->enforcePermissions(
            $collection,
            $payload,
            array_merge($params, ['action' => Acl::ACTION_CREATE])
        );
    }

    protected function enforceUpdatePermissions($collection, array $payload, array $params)
    {
        $this->enforcePermissions(
            $collection,
            $payload,
            array_merge($params, ['action' => Acl::ACTION_UPDATE])
        );
    }

    /**
     * A list of unknown attributes allowed to pass the "unknown field" validation
     *
     * @return array
     */
    protected function unknownFieldsAllowed()
    {
        return [];
    }

    /**
     * Fetches a item in the given collection
     *
     * @param string $collection
     * @param mixed $id
     * @param array $columns
     * @param array $conditions
     *
     * @return BaseRowGateway
     */
    protected function fetchItem($collection, $id, array $columns = null, array $conditions = [])
    {
        $tableGateway = $this->createTableGateway($collection);
        $conditions = array_merge($conditions, [
            $tableGateway->primaryKeyFieldName => $id
        ]);

        $result = $this->fetchItems($collection, $columns, $conditions);

        return $result->current();
    }

    /**
     * @param $collection
     * @param array $columns
     * @param array $conditions
     * @param array $params
     *
     * @return \Zend\Db\ResultSet\ResultSetInterface
     */
    protected function fetchItems($collection, array $columns = null, array $conditions = [], array $params = [])
    {
        $tableGateway = $this->createTableGateway($collection);

        if ($columns === null) {
            $columns = [$tableGateway->primaryKeyFieldName];
        } else if (empty($columns)) {
            $columns = ['*'];
        }

        $select = $tableGateway->getSql()->select();
        $select->columns($columns);
        $select->where($conditions);
        if (ArrayUtils::has($params, 'limit')) {
            $select->limit(ArrayUtils::get($params, 'limit'));
        }

        return $tableGateway->selectWith($select);
    }

    /**
     * Checks whether the given id exists in the given collection
     *
     * @param string $collection
     * @param mixed $id
     * @param array $conditions
     *
     * @return bool
     */
    protected function itemExists($collection, $id, array $conditions = [])
    {
        return $this->fetchItem($collection, $id, null, $conditions) ? true : false;
    }

    /**
     * @param string $collection
     * @param mixed $id
     * @param array $conditions
     *
     * @throws ItemNotFoundException
     */
    protected function checkItemExists($collection, $id, array $conditions = [])
    {
        if (!$this->itemExists($collection, $id, $conditions)) {
            throw new ItemNotFoundException();
        }
    }
}
