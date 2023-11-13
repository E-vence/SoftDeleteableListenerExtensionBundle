<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

use Emdesk\Base\Interfaces\SoftDelete as SoftDeleteEmdesk;
use Secondred\Base\Interfaces\SoftDelete as SoftDeleteSecondred;
use Zend\Log\LoggerServiceFactory;


/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    use ContainerAwareTrait;

    /**
     * @param LifecycleEventArgs $args
     *
     * @throws OnSoftDeleteUnknownTypeException
     */
    public function preSoftDelete(LifecycleEventArgs $args)
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();
        $entity = $args->getEntity();

        $entityReflection = new \ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        $reader = new AnnotationReader();
        foreach ($namespaces as $namespace) {
            $reflectionClass = new \ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                if ($onDelete = $reader->getPropertyAnnotation($property, 'Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete')) {
                    $objects = null;
                    $manyToMany = null;
                    $manyToOne = null;
                    if (($manyToOne = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToOne')) || ($manyToMany = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToMany'))) {

                        if($manyToOne)
                            $relationship = $manyToOne;
                        else
                            $relationship = $manyToMany;

                        $ns = null;
                        $nsOriginal = $relationship->targetEntity;
                        $nsFromRelativeToAbsolute = $entityReflection->getNamespaceName().'\\'.$relationship->targetEntity;
                        $nsFromRoot = '\\'.$relationship->targetEntity;
                        if(class_exists($nsOriginal)){
                            $ns = $nsOriginal;
                        }
                        elseif(class_exists($nsFromRoot)){
                            $ns = $nsFromRoot;
                        }
                        elseif(class_exists($nsFromRelativeToAbsolute)){
                            $ns = $nsFromRelativeToAbsolute;
                        }

                        // versionsfilter deaktivieren
                        $enableFilter = false;
                        if ($em->getFilters()->isEnabled('ProjectVersionAware')) {
                            $em->getFilters()->disable('ProjectVersionAware');
                            $enableFilter = true;
                        }
                        if ($manyToOne && $ns && $entity instanceof $ns) {
                            $objects = $em->getRepository($namespace)->findBy(array(
                                $property->name => $entity,
                            ));
                        }
                        elseif($manyToMany) {

                            if (strtoupper($onDelete->type) === 'SET NULL') {
                                throw new \Exception('SET NULL is not supported for ManyToMany relationships');
                            }

                            $qb = $em->getRepository($namespace)->createQueryBuilder('q')
                                ->join('q.' . $property->name, 'j');

                            /** @var JoinTable $joinTable */
                            $joinTable = $reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\JoinTable');

                            if(!$joinTable){
                                throw new \Exception('No joinTable found for the relationship ' . $namespace. '#'. $property->name);
                            }

                            $columns = $joinTable->joinColumns;
                            $inversedColumns = $joinTable->inverseJoinColumns;

                            if (count($columns) > 1) {
                                throw new \Exception('Only one joinColumn is supported!');
                            }

                            if (count($inversedColumns) > 1) {
                                throw new \Exception('Only one inversedJoinColumns is supported!');
                            }

                            /** @var JoinColumn $joinColumn */
                            $joinColumn = $columns[0];
                            $joinProperty = $this->getPropertyByColumName($reflectionClass, $joinColumn);

                            /** @var JoinColumn $joinColumn */
                            $inversedColumn = $inversedColumns[0];
                            $inversedJoinProperty = $this->getPropertyByColumName($entityReflection, $inversedColumn);



                            if (!$joinProperty){
                                throw new \Exception('No joinColumn found for the relationship between ' .$ns . ' and '. get_class($entity));
                            }


                            if (!$inversedJoinProperty){
                                throw new \Exception('No joinColumn found for the relationship between ' .$ns . ' and '. get_class($entity));
                            }

                            $propertyAccessor = PropertyAccess::createPropertyAccessor();
                            $joinValue = $propertyAccessor->getValue($entity, $inversedJoinProperty->name);

                            $qb->where($qb->expr()->eq('j.'.$joinProperty->name,$joinValue ));

                            $objects = $qb->getQuery()->getResult();

                        }
                        if ($enableFilter) {
                            $em->getFilters()->enable('ProjectVersionAware');
                        }
                    }

                    if ($objects) {
                        foreach ($objects as $object) {
                            if ($object instanceof SoftDeleteEmdesk || $object instanceof SoftDeleteSecondred) {
                                $softDelete = true;
                            } else {
                                $softDelete = false;
                            }

                            if (strtoupper($onDelete->type) === 'SET NULL') {
                                $reflProp = $meta->getReflectionProperty($property->name);
                                $oldValue = $reflProp->getValue($object);

                                $reflProp->setValue($object, null);
                                $em->persist($object);

                                $uow->propertyChanged($object, $property->name, $oldValue, null);
                                $uow->scheduleExtraUpdate($object, array(
                                    $property->name => array($oldValue, null),
                                ));
                            } elseif (strtoupper($onDelete->type) === 'CASCADE') {
                                if ($softDelete) {
                                    $this->softDeleteCascade($em, null, $object);
                                } else {
                                    $em->remove($object);
                                }
                            } else {
                                throw new OnSoftDeleteUnknownTypeException($onDelete->type);
                            }
                        }
                    }
                }
            }
        }
    }


    /**
     * @param EntityManagerInterface $em
     * @param $config
     * @param SoftDeleteEmdesk|SoftDeleteSecondred $object
     */
    protected function softDeleteCascade($em, $config, $object)
    {
        if ($object->isDeleted()) {
            return;
        }

        // remove next level
        $em->remove($object);
    }

    private function getPropertyByColumName(\ReflectionClass $entityReflection, $name){

        $reader = new AnnotationReader();

        foreach ($entityReflection->getProperties() as $p) {
            /** @var $column Column */
            if (($id = $reader->getPropertyAnnotation($p, Id::class)) &&
                ($column = $reader->getPropertyAnnotation($p, Column::class)) &&
                $column->name == $name
            ) {

                return $p;
            }
        }
    }
}
