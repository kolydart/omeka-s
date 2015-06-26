<?php
namespace Omeka\Api\Adapter;

use Omeka\Api\Exception;
use Omeka\Entity\Property;
use Omeka\Entity\Media;
use Omeka\Entity\Resource;
use Omeka\Entity\Value;
use Zend\Stdlib\Hydrator\HydrationInterface;

class ValueHydrator implements HydrationInterface
{
    /**
     * @var AbstractEntityAdapter
     */
    protected $adapter;

    /**
     * @param AbstractEntityAdapter $adapter
     */
    public function __construct(AbstractEntityAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Hydrate all value objects within a JSON-LD node object.
     *
     * The node object represents a resource entity.
     *
     * @param array $nodeObject A JSON-LD node object representing a resource
     * @param Resource $resource The owning resource entity instance
     * @param boolean $append Whether to simply append instead of replacing
     *  existing values
     */
    public function hydrate(array $nodeObject, $resource, $append = false)
    {
        $newValues = array();
        $valueCollection = $resource->getValues();
        $existingValues = $valueCollection->toArray();

        // Iterate all properties in a node object. Note that we ignore terms.
        foreach ($nodeObject as $property => $valueObjects) {
            // Value objects must be contained in lists
            if (!is_array($valueObjects)) {
                continue;
            }
            // Iterate a node object list
            foreach ($valueObjects as $valueObject) {
                // Value objects must be lists
                if (!(is_array($valueObject)
                    && isset($valueObject['property_id']))
                ) {
                    continue;
                }

                $value = current($existingValues);
                if ($value === false || $append) {
                    $value = new Value;
                    $newValues[] = $value;
                } else {
                    // Null out values as we re-use them
                    $existingValues[key($existingValues)] = null;
                    next($existingValues);
                }
                $this->hydrateValue($valueObject, $resource, $value);
            }
        }

        // Remove any values that weren't reused
        if (!$append) {
            foreach ($existingValues as $key => $existingValue) {
                if ($existingValue !== null) {
                    $valueCollection->remove($key);
                }
            }
        }

        // Add any new values that had to be created
        foreach ($newValues as $newValue) {
            $valueCollection->add($newValue);
        }
    }

    /**
     * Hydrate a single JSON-LD value object.
     *
     * Parses the value object according to the existence of certain properties,
     * in order of priority:
     *
     * - @value: persist a literal
     * - value_resource_id: persist a resource value
     * - @id: persist a URI value
     *
     * A value object that contains none of the above combinations is ignored.
     *
     * @param array $valueObject A (potential) JSON-LD value object
     * @param Resource $resource The owning resource entity instance
     * @param Value $value The Value being hydrated
     */
    public function hydrateValue(array $valueObject, Resource $resource, Value $value)
    {
        // Persist a new value
        $property = $this->adapter->getEntityManager()->getReference(
            'Omeka\Entity\Property',
            $valueObject['property_id']
        );

        $value->setResource($resource);
        $value->setProperty($property);

        if (array_key_exists('@value', $valueObject) && $valueObject['@value']) {
            $this->hydrateLiteral($valueObject, $value);
        } elseif (array_key_exists('value_resource_id', $valueObject)) {
            $this->hydrateResource($valueObject, $value);
        } elseif (array_key_exists('@id', $valueObject)) {
            $this->hydrateUri($valueObject, $value);
        }
    }

    /**
     * Hydrate a literal value
     *
     * @param array $valueObject
     * @param Value $value
     */
    protected function hydrateLiteral(array $valueObject, Value $value)
    {
        $value->setType(Value::TYPE_LITERAL);
        $value->setValue($valueObject['@value']);
        if (isset($valueObject['@language'])) {
            $value->setLang($valueObject['@language']);
        } else {
            $value->setLang(null); // set default
        }
        $value->setUriLabel(null); // set default
        $value->setValueResource(null); // set default
    }

    /**
     * Hydrate a resource value
     *
     * @param array $valueObject
     * @param Value $value
     */
    protected function hydrateResource(array $valueObject, Value $value)
    {
        $value->setType(Value::TYPE_RESOURCE);
        $value->setValue(null); // set default
        $value->setLang(null); // set default
        $value->setUriLabel(null); // set default
        $valueResource = $this->adapter->getEntityManager()->find(
            'Omeka\Entity\Resource',
            $valueObject['value_resource_id']
        );
        if (null === $valueResource) {
            throw new Exception\NotFoundException(sprintf(
                $this->adapter->getTranslator()->translate('Resource not found with id %s.'),
                $valueObject['value_resource_id']
            ));
        }
        if ($valueResource instanceof Media) {
            $translator = $this->adapter->getTranslator();
            $exception = new Exception\ValidationException;
            $exception->getErrorStore()->addError(
                'value', $translator->translate('A value resource cannot be Media.')
            );
            throw $exception;
        }
        $value->setValueResource($valueResource);
    }

    /**
     * Hydrate a URI value
     *
     * @param array $valueObject
     * @param Value $value
     */
    protected function hydrateUri(array $valueObject, Value $value)
    {
        $value->setType(Value::TYPE_URI);
        $value->setValue($valueObject['@id']);
        if (isset($valueObject['o:uri_label'])) {
            $value->setUriLabel($valueObject['o:uri_label']);
        } else {
            $value->setUriLabel(null); // set default
        }
        $value->setLang(null); // set default
        $value->setValueResource(null); // set default
    }
}
