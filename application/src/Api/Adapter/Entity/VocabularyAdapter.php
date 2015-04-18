<?php
namespace Omeka\Api\Adapter\Entity;

use Doctrine\ORM\QueryBuilder;
use Omeka\Api\Request;
use Omeka\Model\Entity\EntityInterface;
use Omeka\Stdlib\ErrorStore;

class VocabularyAdapter extends AbstractEntityAdapter
{
    /**
     * {@inheritDoc}
     */
    protected $sortFields = array(
        'id'            => 'id',
        'namespace_uri' => 'namespaceUri',
        'prefix'        => 'prefix',
        'label'         => 'label',
        'comment'       => 'comment',
    );

    /**
     * {@inheritDoc}
     */
    public function getResourceName()
    {
        return 'vocabularies';
    }

    /**
     * {@inheritDoc}
     */
    public function getRepresentationClass()
    {
        return 'Omeka\Api\Representation\Entity\VocabularyRepresentation';
    }

    /**
     * {@inheritDoc}
     */
    public function getEntityClass()
    {
        return 'Omeka\Model\Entity\Vocabulary';
    }

    /**
     * {@inheritDoc}
     */
    public function sortQuery(QueryBuilder $qb, array $query)
    {
        if (is_string($query['sort_by'])) {
            if ('property_count' == $query['sort_by']) {
                $this->sortByCount($qb, $query, 'properties');
            } elseif ('resource_class_count' == $query['sort_by']) {
                $this->sortByCount($qb, $query, 'resourceClasses');
            } else {
                parent::sortQuery($qb, $query);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function hydrate(Request $request, EntityInterface $entity,
        ErrorStore $errorStore
    ) {
        $data = $request->getContent();
        $this->hydrateOwner($request, $entity);

        if ($this->shouldHydrate($request, 'o:namespace_uri')) {
            $entity->setNamespaceUri($request->getValue('o:namespace_uri'));
        }
        if ($this->shouldHydrate($request, 'o:prefix')) {
            $entity->setPrefix($request->getValue('o:prefix'));
        }
        if ($this->shouldHydrate($request, 'o:label')) {
            $entity->setLabel($request->getValue('o:label'));
        }
        if ($this->shouldHydrate($request, 'o:comment')) {
            $entity->setComment($request->getValue('o:comment'));
        }

        if ($this->shouldHydrate($request, 'o:class') && is_array($data['o:class'])) {
            $adapter = $this->getAdapter('resource_classes');
            $class = $adapter->getEntityClass();
            $retainResourceClasses = array();
            $retainResourceClassIds = array();
            foreach ($data['o:class'] as $classData) {
                if (isset($classData['o:id'])) {
                    // Do not update existing resource classes.
                    $retainResourceClassIds[] = $classData['o:id'];
                } else {
                    // Create a new resource class.
                    $resourceClass = new $class;
                    $resourceClass->setVocabulary($entity);
                    $subrequest = new Request(Request::CREATE, 'resource_classes');
                    $subrequest->setContent($classData);
                    $adapter->hydrateEntity($subrequest, $resourceClass, $errorStore);
                    $entity->getResourceClasses()->add($resourceClass);
                    $retainResourceClasses[] = $resourceClass;
                }
            }
            // Remove resource classes not included in request.
            foreach ($entity->getResourceClasses() as $resourceClass) {
                if (!in_array($resourceClass, $retainResourceClasses, true)
                    && !in_array($resourceClass->getId(), $retainResourceClassIds)
                ) {
                    $entity->getResourceClasses()->removeElement($resourceClass);
                }
            }
        }

        if ($this->shouldHydrate($request, 'o:property') && is_array($data['o:property'])) {
            $adapter = $this->getAdapter('properties');
            $class = $adapter->getEntityClass();
            $retainProperties = array();
            $retainPropertyIds = array();
            foreach ($data['o:property'] as $propertyData) {
                if (isset($propertyData['o:id'])) {
                    // Do not update existing properties.
                    $retainPropertyIds[] = $propertyData['o:id'];
                } else {
                    // Create a new property.
                    $property = new $class;
                    $property->setVocabulary($entity);
                    $subrequest = new Request(Request::CREATE, 'properties');
                    $subrequest->setContent($propertyData);
                    $adapter->hydrateEntity($subrequest, $property, $errorStore);
                    $entity->getProperties()->add($property);
                    $retainProperties[] = $property;
                }
            }
            // Remove resource classes not included in request.
            foreach ($entity->getProperties() as $property) {
                if (!in_array($property, $retainProperties, true)
                    && !in_array($property->getId(), $retainPropertyIds)
                ) {
                    $entity->getProperties()->removeElement($property);
                }
            }
        }

    }

    /**
     * {@inheritDoc}
     */
    public function buildQuery(QueryBuilder $qb, array $query)
    {
        if (isset($query['owner_id'])) {
            $userAlias = $this->createAlias();
            $qb->innerJoin(
                'Omeka\Model\Entity\Vocabulary.owner',
                $userAlias
            );
            $qb->andWhere($qb->expr()->eq(
                "$userAlias.id",
                $this->createNamedParameter($qb, $query['owner_id']))
            );
        }
        if (isset($query['namespace_uri'])) {
            $qb->andWhere($qb->expr()->eq(
                "Omeka\Model\Entity\Vocabulary.namespaceUri",
                $this->createNamedParameter($qb, $query['namespace_uri']))
            );
        }
        if (isset($query['prefix'])) {
            $qb->andWhere($qb->expr()->eq(
                "Omeka\Model\Entity\Vocabulary.prefix",
                $this->createNamedParameter($qb, $query['prefix']))
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validateEntity(EntityInterface $entity, ErrorStore $errorStore)
    {
        // Validate namespace URI
        $namespaceUri = $entity->getNamespaceUri();
        if (false == $entity->getNamespaceUri()) {
            $errorStore->addError('o:namespace_uri', 'The namespace URI cannot be empty.');
        }
        if (!$this->isUnique($entity, array('namespaceUri' => $namespaceUri))) {
            $errorStore->addError('o:namespace_uri', sprintf(
                'The namespace URI "%s" is already taken.',
                $namespaceUri
            ));
        }

        // Validate prefix
        $prefix = $entity->getPrefix();
        if (false == $entity->getPrefix()) {
            $errorStore->addError('o:prefix', 'The prefix cannot be empty.');
        }
        if (!$this->isUnique($entity, array('prefix' => $prefix))) {
            $errorStore->addError('o:prefix', sprintf(
                'The prefix "%s" is already taken.',
                $prefix
            ));
        }

        // Validate label
        if (false == $entity->getLabel()) {
            $errorStore->addError('o:label', 'The label cannot be empty.');
        }

        // Check for uniqueness of resource class local names.
        $uniqueLocalNames = array();
        foreach ($entity->getResourceClasses() as $resourceClass) {
            if (in_array($resourceClass->getLocalName(), $uniqueLocalNames)) {
                $errorStore->addError('o:resource_class', sprintf(
                    'The local name "%s" is already taken.',
                    $resourceClass->getLocalName()
                ));
            } else {
                $uniqueLocalNames[] = $resourceClass->getLocalName();
            }
        }

        // Check for uniqueness of property local names.
        $uniqueLocalNames = array();
        foreach ($entity->getProperties() as $property) {
            if (in_array($property->getLocalName(), $uniqueLocalNames)) {
                $errorStore->addError('o:resource_class', sprintf(
                    'The local name "%s" is already taken.',
                    $property->getLocalName()
                ));
            } else {
                $uniqueLocalNames[] = $property->getLocalName();
            }
        }
    }
}
