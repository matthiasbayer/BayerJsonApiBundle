<?php

namespace Bayer\Bundle\JsonApiBundle\View;

use Bayer\JsonApi\Document\AbstractDocument;
use Bayer\JsonApi\Document\CollectionDocument;
use Bayer\JsonApi\Document\ResourceDocument;
use Bayer\JsonApi\Relationship\ToManyRelationship;
use Bayer\JsonApi\Relationship\ToOneRelationship;
use Bayer\JsonApi\Resource\ResourceIdentifier;
use Bayer\JsonApi\Resource\ResourceObject;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use FOS\RestBundle\View\View;
use FOS\RestBundle\View\ViewHandler;
use JMS\Serializer\AbstractVisitor;
use JMS\Serializer\Metadata\PropertyMetadata;
use JMS\Serializer\Naming\PropertyNamingStrategyInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Metadata\ClassHierarchyMetadata;
use Metadata\MergeableClassMetadata;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class JsonApiHandler
{
    /**
     * @var ManagerRegistry
     */
    protected $managerRegistry;

    /**
     * @param ManagerRegistry $managerRegistry
     * @param SerializerInterface $serializer
     */
    public function __construct(ManagerRegistry $managerRegistry, SerializerInterface $serializer)
    {
        $this->managerRegistry = $managerRegistry;
        $this->serializer = $serializer;
    }

    /**
     * @param ViewHandler $viewHandler
     * @param View $view
     * @param Request $request
     * @param string $format
     *
     * @return Response
     */
    public function handle(ViewHandler $viewHandler, View $view, Request $request, $format)
    {
        $included = $this->buildIncludedArray(explode(',', $request->query->get('included', '')));
        $data = $view->getData();

        if (is_array($data) || $data instanceof \Traversable) {
            $document = new CollectionDocument();

            foreach ($data as $resourceData) {
                $document->addData($this->buildResourceObject($document, $resourceData, $included));
            }
        } else {
            $document = new ResourceDocument();
            $document->setData($this->buildResourceObject($document, $data, $included));
        }

        $response = new Response(
            $this->serializer->serialize($document, 'json'),
            200,
            array(
                'Content-Type' => 'application/vnd.api+json'
            )
        );

        return $response;
    }

    /**
     * @param AbstractDocument $document
     * @param object $data
     * @param array $included
     * @param array|null $includeRelationships
     * @return ResourceObject
     * @throws \Exception
     */
    public function buildResourceObject(AbstractDocument $document, $data, array $included = array(), $includeRelationships = null)
    {
        list(, $idValue, $objectType) = $this->getResourceInfo($data);
        $resourceObject = new ResourceObject($idValue, $objectType);
        $objectMetadata = $this->getClassMetadata($data);

        /** @var array $serializedData */
        /** @var ClassHierarchyMetadata|MergeableClassMetadata $serializationMetadata */
        /** @var PropertyNamingStrategyInterface $namingStrategy */
        list($serializedData, $serializationMetadata, $namingStrategy) = $this->getSerializationData($data);

        foreach ($serializationMetadata->propertyMetadata as $propertyMetadata) {
            /** @var PropertyMetadata $propertyMetadata */

            // Translate the object property name into the serialized format. We need to know how the property
            // is named because we use this name to read the value from the serialized object representation.
            $serializedPropertyName = $propertyMetadata->serializedName;

            if (null === $serializedPropertyName ) {
                $serializedPropertyName = $namingStrategy->translateName($propertyMetadata);
            }

            // Get the serialized value. Reading the serialized value ensures that all serializer settings and
            // annotations are respected before transforming the data into the json api format.
            if (!isset($serializedData[$serializedPropertyName])) {
                throw new \RuntimeException("Unable to read property '$serializedPropertyName'");
            }

            $serializedPropertyValue = $serializedData[$serializedPropertyName];

            // Identifiers must not be part of the attributes or relationships list
            if ($objectMetadata->isIdentifier($propertyMetadata->name)) {
                continue;
            }

            // Handle doctrine to-one/to-many relationships according to the json api specification.
            if ($objectMetadata->hasAssociation($propertyMetadata->name)) {
                // Skip the current relationship if a list of allowed relationships is explicitly set and the current
                // property is not included in the list.
                if (is_array($includeRelationships) && !in_array($serializedPropertyName, $includeRelationships)) {
                    continue;
                }

                if ($objectMetadata->isSingleValuedAssociation($propertyMetadata->name)) {
                    $relationship = new ToOneRelationship();
                    $associationValue = $propertyMetadata->getValue($data);
                    $relationship->setData($this->buildResourceIdentifier($associationValue));
                    $resourceObject->setRelationship($serializedPropertyName, $relationship);

                    if (isset($included[$serializedPropertyName])) {
                        $document->addIncluded($this->buildResourceObject(
                            $document,
                            $associationValue,
                            $included[$serializedPropertyName],
                            array_keys($included[$serializedPropertyName])
                        ));
                    }
                } else if ($objectMetadata->isCollectionValuedAssociation($propertyMetadata->name)) {
                    $relationship = new ToManyRelationship();

                    foreach ($propertyMetadata->getValue($data) as $associationValue) {
                        $relationship->addData($this->buildResourceIdentifier($associationValue));

                        if (isset($included[$serializedPropertyName])) {
                            $document->addIncluded($this->buildResourceObject(
                                $document,
                                $associationValue,
                                $included[$serializedPropertyName],
                                array_keys($included[$serializedPropertyName])
                            ));
                        }
                    }

                    $resourceObject->setRelationship($serializedPropertyName, $relationship);
                }
            } else {
                // All values that are no relationship must be added to the attributes list
                $resourceObject->setAttribute($serializedPropertyName, $serializedPropertyValue);
            }
        }

        return $resourceObject;
    }

    /**
     * @param object $data
     * @return ResourceIdentifier
     */
    protected function buildResourceIdentifier($data)
    {
        list(, $idValue, $objectType) = $this->getResourceInfo($data);

        return new ResourceIdentifier($idValue, $objectType);
    }

    /**
     * Return an array with id property name, id value and object type
     *
     * @param object $data
     * @return array
     */
    protected function getResourceInfo($data)
    {
        $metadata = $this->getClassMetadata($data);

        $objectIdentifier = $metadata->getIdentifierFieldNames();

        if (count($objectIdentifier) !== 1) {
            throw new \RuntimeException("The object must contain exactly one identifier member");
        }

        $identifierName = $objectIdentifier[0];

        if (!$metadata->getReflectionClass()->hasProperty($identifierName)) {
            throw new \RuntimeException("Identifier member '$identifierName' not found in object");
        }

        $identifierProperty = $metadata->getReflectionClass()
            ->getProperty($identifierName);
        $identifierProperty->setAccessible(true);

        $identifierValue = $identifierProperty->getValue($data);
        $objectType = strtolower($metadata->getReflectionClass()->getShortName());

        return array($identifierName, $identifierValue, $objectType);
    }

    /**
     * Convert an array of dot.separated.values into a multi dimensional array representation.
     *
     * @param array $list
     * @return array
     */
    protected function buildIncludedArray(array $list)
    {
        $return = array();

        foreach ($list as $identifier) {
            $parts = explode('.', $identifier);

            $prt = &$return;

            foreach ($parts as $part) {
                if (!isset($prt[$part])) {
                    $prt[$part] = array();
                }

                $prt = &$prt[$part];
            }
        }

        return $return;
    }

    /**
     * Serialize $data and return the serialized data and the serialization metadata
     *
     * @param object $data
     * @return array
     * @throws \Exception
     */
    protected function getSerializationData($data)
    {
        $className = get_class($data);
        $context = new SerializationContext();
        $serializedData = $this->serializer->serialize($data, 'json', $context);

        $visitor = $context->getVisitor();
        $namingStrategy = null;

        if ($visitor instanceof AbstractVisitor) {
            $namingStrategy = $visitor->getNamingStrategy();
        } else {
            throw new \Exception('Could not get serializer naming strategy');
        }

        $metadata = $context->getMetadataFactory()->getMetadataForClass($className);

        if (null === $metadata) {
            throw new \RuntimeException("Could not load serialization metadata for class '$className'");
        }

        return array(json_decode($serializedData, true), $metadata, $namingStrategy);
    }

    /**
     * @param string|object $class
     * @return ClassMetadata
     */
    protected function getClassMetadata($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        if (!is_string($class)) {
            throw new \InvalidArgumentException("Argument must be either string or object, got " . gettype($class));
        }

        $objectManager = $this->managerRegistry->getManagerForClass($class);

        if (null === $objectManager) {
            throw new \RuntimeException("Could not find object manager for class '$class'");
        }

        return $objectManager->getClassMetadata($class);
    }
}