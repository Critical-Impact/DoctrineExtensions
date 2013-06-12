<?php

namespace Gedmo\Sortable;

use Doctrine\Common\EventArgs;
use Gedmo\Mapping\MappedEventSubscriber;
use Gedmo\Sluggable\Mapping\Event\SortableAdapter;

/**
 * The SortableListener maintains a sort index on your entities
 * to enable arbitrary sorting.
 *
 * This behavior can inpact the performance of your application
 * since it does some additional calculations on persisted objects.
 *
 * @author Lukas Botsch <lukas.botsch@gmail.com>
 * @subpackage SortableListener
 * @package Gedmo.Sortable
 * @link http://www.gediminasm.org
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
class SortableListener extends MappedEventSubscriber
{
    private $relocations = array();
    private $maxPositions = array();
    
    /**
     * Specifies the list of events to listen
     *
     * @return array
     */
    public function getSubscribedEvents()
    {
        return array(
            'prePersist',
            'onFlush',
            'loadClassMetadata'
        );
    }
    
    public function prePersist(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $object = $ea->getObject();
        $meta = $om->getClassMetadata(get_class($object));
        if ($config = $this->getConfiguration($om, $meta->name)) {
            if (isset($config['position'])
                    && is_null($meta->getReflectionProperty($config['position'])->getValue($object))) {
                $meta->getReflectionProperty($config['position'])->setValue($object, -1);
            }
        }
    }
    
    /**
     * Mapps additional metadata
     *
     * @param EventArgs $eventArgs
     * @return void
     */
    public function loadClassMetadata(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $this->loadMetadataForObjectClass($ea->getObjectManager(), $args->getClassMetadata());
    }

    /**
     * Generate slug on objects being updated during flush
     * if they require changing
     *
     * @param EventArgs $args
     * @return void
     */
    public function onFlush(EventArgs $args)
    {
        $ea = $this->getEventAdapter($args);
        $om = $ea->getObjectManager();
        $uow = $om->getUnitOfWork();
        
        // process all objects beeing deleted
        foreach ($ea->getScheduledObjectDeletions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($config = $this->getConfiguration($om, $meta->name)) {
                $this->processDeletion($om, $config, $meta, $object);
            }
        }
        
        // process all objects beeing updated
        foreach ($ea->getScheduledObjectUpdates($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($config = $this->getConfiguration($om, $meta->name)) {
                $this->processUpdate($om, $config, $meta, $object);
            }
        }
        
        // process all objects beeing inserted
        foreach ($ea->getScheduledObjectInsertions($uow) as $object) {
            $meta = $om->getClassMetadata(get_class($object));
            if ($config = $this->getConfiguration($om, $meta->name)) {
                $this->processInsert($om, $config, $meta, $object);
            }
        }
        
        $this->processRelocations($om);
    }
    
    /**
     * Computes node positions and updates the sort field in memory and in the db
     * @param object $em ObjectManager
     */
    private function processInsert($em, $config, $meta, $object)
    {
        $uow = $em->getUnitOfWork();
        
        $newPosition = $meta->getReflectionProperty($config['position'])->getValue($object);
        if (is_null($newPosition)) {
            $newPosition = -1;
        }
        
        // Get groups
        $groups = array();
        if (isset($config['groups'])) {
            foreach ($config['groups'] as $group) {
                $groups[$group] = $meta->getReflectionProperty($group)->getValue($object);
            }
        }

        // Get hash
        $hash = $this->getHash($meta, $groups, $object);
        
        // Get max position
        if (!isset($this->maxPositions[$hash])) {
            $this->maxPositions[$hash] = $this->getMaxPosition($em, $meta->name, $config['position'], $groups);
        }
        
        // Compute position if it is negative
        if ($newPosition < 0) {
            $newPosition += $this->maxPositions[$hash] + 2; // position == -1 => append at end of list
            if ($newPosition < 0) $newPosition = 0;
        }
        
        // Set position to max position if it is too big
        $newPosition = min(array($this->maxPositions[$hash] + 1, $newPosition));
        
        // Compute relocations
        $relocation = array($hash, $meta, $groups, $newPosition, -1, +1);
        
        // Apply existing relocations
        $applyDelta = 0;
        if (isset($this->relocations[$hash])) {
            foreach ($this->relocations[$hash]['deltas'] as $delta) {
                if ($delta['start'] <= $newPosition
                        && ($delta['stop'] > $newPosition || $delta['stop'] < 0)) {
                    $applyDelta += $delta['delta'];
                }
            }
        }
        $newPosition += $applyDelta;
        
        // Add relocations
        call_user_func_array(array($this, 'addRelocation'), $relocation);
        
        // Set new position
        $meta->getReflectionProperty($config['position'])->setValue($object, $newPosition);
        $uow->recomputeSingleEntityChangeSet($meta, $object);
    }

    /**
     * Computes node positions and updates the sort field in memory and in the db
     * @param object $em ObjectManager
     */
    private function processUpdate($em, $config, $meta, $object)
    {
        $uow = $em->getUnitOfWork();
        
        $changed = false;
        $changeSet = $uow->getEntityChangeSet($object);
        
        // Get groups
        $groups = array();
        $oldGroups = array();
        if (isset($config['groups'])) {
            foreach ($config['groups'] as $group) {
                $groupChanged = (array_key_exists($group, $changeSet)
                        && $changeSet[$group][0] != $changeSet[$group][1]);
                
                $changed = $changed || $groupChanged;
                $groups[$group] = $meta->getReflectionProperty($group)->getValue($object);
                
                if($groupChanged) {
                    $oldGroups[$group] = $changeSet[$group][0];
                }
            }
        }
        
        if (array_key_exists($config['position'], $changeSet)) {
            $oldPosition = $changeSet[$config['position']][0];
            $newPosition = $changeSet[$config['position']][1];
        }
        else
        {
            if(sizeof($oldGroups)) {
                $oldHash = $this->getHash($meta, $oldGroups, $object);
                if (!isset($this->maxPositions[$oldHash])) {
                    $this->maxPositions[$oldHash] = $this->getMaxPosition($em, $meta->name, $config['position'], $oldGroups);
                }
                $this->addRelocation($oldHash, $meta, $oldGroups, $object->getPosition() + 1, $this->maxPositions[$oldHash] + 1, -1);
                
                // update position for 
                $object->setPosition($this->getMaxPosition($em, $meta->name, $config['position'], $groups)+1);
                $uow->recomputeSingleEntityChangeSet($em->getClassMetadata(get_class($object)), $object);
            }
            return;
        }
        
        $changed = $changed || $oldPosition != $newPosition;
        
        if (!$changed) return;
        
        // Get hash
        $hash = $this->getHash($meta, $groups, $object);
        
        // Get max position
        if (!isset($this->maxPositions[$hash])) {
            $this->maxPositions[$hash] = $this->getMaxPosition($em, $meta->name, $config['position'], $groups);
        }

        // Compute position if it is negative
        if ($newPosition < 0) {
            $newPosition += $this->maxPositions[$hash] + 2; // position == -1 => append at end of list
            if ($newPosition < 0) $newPosition = 0;
        }
        
        // Set position to max position if it is too big
        $newPosition = min(array($this->maxPositions[$hash], $newPosition));
        
        // Compute relocations
        /*
        CASE 1: shift backwards
        |----0----|----1----|----2----|----3----|----4----|
        |--node1--|--node2--|--node3--|--node4--|--node5--|
        Update node4: setPosition(1)
        --> Update position + 1 where position in [1,3)
        |--node1--|--node4--|--node2--|--node3--|--node5--|
        CASE 2: shift forward
        |----0----|----1----|----2----|----3----|----4----|
        |--node1--|--node2--|--node3--|--node4--|--node5--|
        Update node2: setPosition(3)
        --> Update position - 1 where position in (1,3]
        |--node1--|--node3--|--node4--|--node2--|--node5--|
        */
        $relocation = null;
        if ($newPosition < $oldPosition) {
            $relocation = array($hash, $meta, $groups, $newPosition, $oldPosition, +1);
        } elseif ($newPosition > $oldPosition) {
            $relocation = array($hash, $meta, $groups, $oldPosition + 1, $newPosition + 1, -1);
        }
        
        // Apply existing relocations
        $applyDelta = 0;
        if (isset($this->relocations[$hash])) {
            foreach ($this->relocations[$hash]['deltas'] as $delta) {
                if ($delta['start'] <= $newPosition
                        && ($delta['stop'] > $newPosition || $delta['stop'] < 0)) {
                    $applyDelta += $delta['delta'];
                }
            }
        }
        $newPosition += $applyDelta;
        
        // Add relocation
        if($relocation) {
            call_user_func_array(array($this, 'addRelocation'), $relocation);
        }
        
        // Set new position
        $meta->getReflectionProperty($config['position'])->setValue($object, $newPosition);
        $uow->recomputeSingleEntityChangeSet($meta, $object);
    }
    
    /**
     * Computes node positions and updates the sort field in memory and in the db
     * @param object $em ObjectManager
     */
    private function processDeletion($em, $config, $meta, $object)
    {
        $position = $meta->getReflectionProperty($config['position'])->getValue($object);
        
        // Get groups
        $groups = array();
        if (isset($config['groups'])) {
            foreach ($config['groups'] as $group) {
                $groups[$group] = $meta->getReflectionProperty($group)->getValue($object);
            }
        }

        // Get hash
        $hash = $this->getHash($meta, $groups, $object);
        
        // Get max position
        if (!isset($this->maxPositions[$hash])) {
            $this->maxPositions[$hash] = $this->getMaxPosition($em, $meta->name, $config['position'], $groups);
        }
        
        // Add relocation
        $this->addRelocation($hash, $meta, $groups, $position, -1, -1);
    }
    
    private function processRelocations($em)
    {
        foreach ($this->relocations as $hash => $relocation) {
            $config = $this->getConfiguration($em, $relocation['name']);
            foreach ($relocation['deltas'] as $delta) {
                if ($delta['start'] > $this->maxPositions[$hash] || $delta['delta'] == 0) {
                    continue;
                }
                $sign = $delta['delta'] < 0 ? "-" : "+";
                $absDelta = abs($delta['delta']);
                $qb = $em->createQueryBuilder();
                $qb->update($relocation['name'], 'n')
                   ->set("n.{$config['position']}", "n.{$config['position']} ".$sign." :delta")
                   ->where("n.{$config['position']} >= :start")
                   ->setParameter('delta', $absDelta)
                   ->setParameter('start', $delta['start']);
                if ($delta['stop'] > 0) {
                    $qb->andWhere("n.{$config['position']} < :stop")
                       ->setParameter('stop', $delta['stop']);
                }
                $i = 1;
                foreach ($relocation['groups'] as $group => $val) {
                    $qb->andWhere('n.'.$group." = :group".$i)
                       ->setParameter('group'.$i, $val);
                    $i++;
                }
                $qb->getQuery()->getResult();
            }
        }

        // Clear relocations
        $this->relocations = array();
        $this->maxPositions = array();
    }
    
    private function getHash($meta, $groups, $object)
    {
        $data = $meta->name;
        foreach ($groups as $group => $val) {
            if (is_object($val)) {
                $val = spl_object_hash($val);
            }
            $data .= $group.$val;
        }
        return md5($data);
    }
    
    /**
     * $groups = array(
     *      'label' => $object,
     *      ...
     *      )
     * where 'label' is the group attribute inside original entity
     * and $object is the the group object
     * 
     * @param EntityManager $em
     * @param string $entityLabel
     * @param string $positionLabel
     * @param array $groups
     * 
     * @return integer
     */
    private function getMaxPosition($em, $entityLabel, $positionLabel, array $groups = array())
    {
        $maxPos = null;

            $qb = $em->createQueryBuilder();
            $qb->select('COUNT(n)')
                ->from($entityLabel, 'n');
            $qb = $this->addGroupWhere($qb, $groups);
            $query = $qb->getQuery();
            $query->useQueryCache(false);
            $query->useResultCache(false);
            $res = $query->getResult();
            $maxPos = $res[0][1];


        return $maxPos;
    }
    
    private function addGroupWhere($qb, $groups)
    {
        $i = 1;
        foreach ($groups as $label=>$object) {
            //$qb->andWhere('n.'.$group." = '".$meta->getReflectionProperty($group)->getValue($object)."'");
            $qb->andWhere('n.'.$label.' = :group'.$i);
            $qb->setParameter('group'.$i, $object);
            $i++;
        }
        return $qb;
    }
    
    /**
     * Add a relocation rule
     * @param string $hash The hash of the sorting group
     * @param $meta The objects meta data
     * @param array $groups The sorting groups
     * @param int $start Inclusive index to start relocation from
     * @param int $stop Exclusive index to stop relocation at
     * @param int $delta The delta to add to relocated nodes
     */
    private function addRelocation($hash, $meta, $groups, $start, $stop, $delta)
    {
        if (!array_key_exists($hash, $this->relocations)) {
            $this->relocations[$hash] = array('name' => $meta->name, 'groups' => $groups, 'deltas' => array());
        }
        
        try {
            $newDelta = array('start' => $start, 'stop' => $stop, 'delta' => $delta);
            array_walk($this->relocations[$hash]['deltas'], function(&$val, $idx, $needle) {
                if ($val['start'] == $needle['start'] && $val['stop'] == $needle['stop']) {
                    $val['delta'] += $needle['delta'];
                    throw new \Exception("Found delta. No need to add it again.");
                }
            }, $newDelta);
            $this->relocations[$hash]['deltas'][] = $newDelta;
        } catch (\Exception $e) {}
    }
    
    /**
     * {@inheritDoc}
     */
    protected function getNamespace()
    {
        return __NAMESPACE__;
    }
}