<?php

namespace Oro\Bundle\WorkflowBundle\Tests\Unit\Model;

use Doctrine\Common\Collections\ArrayCollection;

use Oro\Bundle\WorkflowBundle\Entity\WorkflowDefinition;
use Oro\Bundle\WorkflowBundle\Model\WorkflowManager;
use Oro\Bundle\WorkflowBundle\Model\Workflow;
use Oro\Bundle\WorkflowBundle\Model\Attribute;
use Oro\Bundle\WorkflowBundle\Model\Transition;
use Oro\Bundle\WorkflowBundle\Entity\WorkflowItem;
use Oro\Bundle\WorkflowBundle\Model\AttributeManager;
use Oro\Bundle\WorkflowBundle\Model\TransitionManager;

class WorkflowManagerTest extends \PHPUnit_Framework_TestCase
{
    const TEST_WORKFLOW_NAME = 'test_workflow';

    /**
     * @var WorkflowManager
     */
    protected $workflowManager;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $registry;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $workflowRegistry;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $doctrineHelper;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    protected $configManager;

    protected function setUp()
    {
        $this->registry = $this->getMockBuilder('Doctrine\Common\Persistence\ManagerRegistry')
            ->disableOriginalConstructor()
            ->setMethods('getManager')
            ->getMockForAbstractClass();

        $this->workflowRegistry = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\WorkflowRegistry')
            ->disableOriginalConstructor()
            ->getMock();

        $this->doctrineHelper = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\DoctrineHelper')
            ->disableOriginalConstructor()
            ->getMock();

        $this->configManager = $this->getMockBuilder('Oro\Bundle\EntityConfigBundle\Config\ConfigManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->workflowManager = new WorkflowManager(
            $this->registry,
            $this->workflowRegistry,
            $this->doctrineHelper,
            $this->configManager
        );
    }

    protected function tearDown()
    {
        unset($this->registry);
        unset($this->workflowRegistry);
        unset($this->doctrineHelper);
        unset($this->workflowManager);
    }

    public function testGetStartTransitions()
    {
        $startTransition = new Transition();
        $startTransition->setName('start_transition');
        $startTransition->setStart(true);

        $startTransitions = new ArrayCollection(array($startTransition));
        $workflow = $this->createWorkflow('test_workflow', array(), $startTransitions->toArray());
        $this->assertEquals($startTransitions, $this->workflowManager->getStartTransitions($workflow));
    }

    public function testGetTransitionsByWorkflowItem()
    {
        $workflowName = 'test_workflow';

        $workflowItem = new WorkflowItem();
        $workflowItem->setWorkflowName($workflowName);

        $transition = new Transition();
        $transition->setName('test_transition');

        $transitions = new ArrayCollection(array($transition));

        $workflow = $this->createWorkflow($workflowName);
        $workflow->expects($this->once())
            ->method('getTransitionsByWorkflowItem')
            ->with($workflowItem)
            ->will($this->returnValue($transitions));

        $this->workflowRegistry->expects($this->once())
            ->method('getWorkflow')
            ->with($workflowName)
            ->will($this->returnValue($workflow));

        $this->assertEquals(
            $transitions,
            $this->workflowManager->getTransitionsByWorkflowItem($workflowItem)
        );
    }

    public function testIsTransitionAvailable()
    {
        $workflowName = 'test_workflow';

        $workflowItem = new WorkflowItem();
        $workflowItem->setWorkflowName($workflowName);

        $errors = new ArrayCollection();

        $transition = new Transition();
        $transition->setName('test_transition');

        $workflow = $this->createWorkflow($workflowName);
        $workflow->expects($this->once())
            ->method('isTransitionAvailable')
            ->with($workflowItem, $transition, $errors)
            ->will($this->returnValue(true));

        $this->workflowRegistry->expects($this->once())
            ->method('getWorkflow')
            ->with($workflowName)
            ->will($this->returnValue($workflow));

        $this->assertTrue($this->workflowManager->isTransitionAvailable($workflowItem, $transition, $errors));
    }

    public function testIsStartTransitionAvailable()
    {
        $workflowName = 'test_workflow';
        $errors = new ArrayCollection();
        $entity = new \DateTime('now');
        $data = array();

        $entityAttribute = new Attribute();
        $entityAttribute->setName('entity_attribute');
        $entityAttribute->setType('entity');
        $entityAttribute->setOptions(array('class' => 'DateTime', 'managed_entity' => true));

        $stringAttribute = new Attribute();
        $stringAttribute->setName('other_attribute');
        $stringAttribute->setType('string');

        $transition = 'test_transition';

        $workflow = $this->createWorkflow($workflowName, array($entityAttribute, $stringAttribute));
        $workflow->expects($this->once())
            ->method('isStartTransitionAvailable')
            ->with($transition, $entity, $data, $errors)
            ->will($this->returnValue(true));

        $this->workflowRegistry->expects($this->once())
            ->method('getWorkflow')
            ->with($workflowName)
            ->will($this->returnValue($workflow));

        $this->assertTrue(
            $this->workflowManager->isStartTransitionAvailable($workflowName, $transition, $entity, $data, $errors)
        );
    }

    public function testStartWorkflow()
    {
        $entity = new \DateTime();
        $transition = 'test_transition';
        $workflowData = array('key' => 'value');
        $workflowItem = new WorkflowItem();
        $workflowItem->getData()->add($workflowData);

        $workflow = $this->createWorkflow();
        $workflow->expects($this->once())
            ->method('start')
            ->with($entity, $workflowData, $transition)
            ->will($this->returnValue($workflowItem));

        $entityManager = $this->createEntityManager();
        $entityManager->expects($this->once())
            ->method('beginTransaction');
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($workflowItem);
        $entityManager->expects($this->once())
            ->method('flush');
        $entityManager->expects($this->once())
            ->method('commit');

        $this->registry->expects($this->once())
            ->method('getManager')
            ->will($this->returnValue($entityManager));

        $actualWorkflowItem = $this->workflowManager->startWorkflow($workflow, $entity, $transition, $workflowData);

        $this->assertEquals($workflowItem, $actualWorkflowItem);
        $this->assertEquals($workflowData, $actualWorkflowItem->getData()->getValues());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Start workflow exception message
     */
    public function testStartWorkflowException()
    {
        $entityManager = $this->createEntityManager();
        $entityManager->expects($this->once())
            ->method('beginTransaction');
        $entityManager->expects($this->once())
            ->method('persist')
            ->will($this->throwException(new \Exception('Start workflow exception message')));
        $entityManager->expects($this->once())
            ->method('rollback');

        $this->registry->expects($this->once())
            ->method('getManager')
            ->will($this->returnValue($entityManager));

        $this->workflowManager->startWorkflow($this->createWorkflow(), null, 'test_transition');
    }

    public function testTransit()
    {
        $transition = 'test_transition';
        $workflowName = 'test_workflow';

        $workflowItem = new WorkflowItem();
        $workflowItem->setWorkflowName($workflowName);

        $workflow = $this->createWorkflow($workflowName);
        $workflow->expects($this->once())
            ->method('transit')
            ->with($workflowItem, $transition);

        $this->workflowRegistry->expects($this->once())
            ->method('getWorkflow')
            ->with($workflowName)
            ->will($this->returnValue($workflow));

        $entityManager = $this->createEntityManager();
        $entityManager->expects($this->once())
            ->method('beginTransaction');
        $entityManager->expects($this->once())
            ->method('flush');
        $entityManager->expects($this->once())
            ->method('commit');

        $this->registry->expects($this->once())
            ->method('getManager')
            ->will($this->returnValue($entityManager));

        $this->assertEmpty($workflowItem->getUpdatedAt());
        $this->workflowManager->transit($workflowItem, $transition);
        $this->assertNotEmpty($workflowItem->getUpdatedAt());
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage Transit exception message
     */
    public function testTransitException()
    {
        $workflowName = 'test_workflow';

        $workflowItem = new WorkflowItem();
        $workflowItem->setWorkflowName($workflowName);

        $this->workflowRegistry->expects($this->once())
            ->method('getWorkflow')
            ->with($workflowName)
            ->will($this->returnValue($this->createWorkflow($workflowName)));

        $entityManager = $this->createEntityManager();
        $entityManager->expects($this->once())
            ->method('beginTransaction');
        $entityManager->expects($this->once())
            ->method('flush')
            ->will($this->throwException(new \Exception('Transit exception message')));
        $entityManager->expects($this->once())
            ->method('rollback');

        $this->registry->expects($this->once())
            ->method('getManager')
            ->will($this->returnValue($entityManager));

        $this->workflowManager->transit($workflowItem, 'test_transition');
    }

    public function testGetApplicableWorkflow()
    {
        $entity = new \DateTime('now');
        $entityClass = get_class($entity);
        $workflow = $this->createWorkflow(self::TEST_WORKFLOW_NAME);

        $this->doctrineHelper->expects($this->once())
            ->method('getEntityClass')
            ->with($entity)
            ->will($this->returnValue($entityClass));
        $this->workflowRegistry->expects($this->once())
            ->method('getActiveWorkflowByEntityClass')
            ->with($entityClass)
            ->will($this->returnValue($workflow));

        $this->assertEquals($workflow, $this->workflowManager->getApplicableWorkflow($entity));
    }

    public function testGetWorkflowItemByEntity()
    {
        $entity = new \DateTime('now');
        $entityClass = get_class($entity);
        $entityId = 1;

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityClass')
            ->with($entity)
            ->will($this->returnValue($entityClass));

        $this->doctrineHelper->expects($this->any())
            ->method('getEntityIdentifier')
            ->with($entity)
            ->will($this->returnValue($entityId));

        $workflowItem = $this->createWorkflowItem();

        $workflowItemsRepository =
            $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Entity\Repository\WorkflowItemRepository')
                ->disableOriginalConstructor()
                ->setMethods(array('findByEntityMetadata'))
                ->getMock();
        $workflowItemsRepository->expects($this->any())
            ->method('findByEntityMetadata')
            ->with($entityClass, $entityId)
            ->will($this->returnValue($workflowItem));
        $this->registry->expects($this->any())
            ->method('getRepository')
            ->with('OroWorkflowBundle:WorkflowItem')
            ->will($this->returnValue($workflowItemsRepository));

        $this->assertEquals(
            $workflowItem,
            $this->workflowManager->getWorkflowItemByEntity($entity)
        );
    }

    /**
     * @param mixed $workflowIdentifier
     * @dataProvider getWorkflowDataProvider
     */
    public function testGetWorkflow($workflowIdentifier)
    {
        $expectedWorkflow = $this->createWorkflow(self::TEST_WORKFLOW_NAME);

        if ($workflowIdentifier instanceof Workflow) {
            $this->workflowRegistry->expects($this->never())
                ->method('getWorkflow');
        } else {
            $this->workflowRegistry->expects($this->any())
                ->method('getWorkflow')
                ->with(self::TEST_WORKFLOW_NAME)
                ->will($this->returnValue($expectedWorkflow));
        }

        $this->assertEquals($expectedWorkflow, $this->workflowManager->getWorkflow($workflowIdentifier));
    }

    /**
     * @return array
     */
    public function getWorkflowDataProvider()
    {
        return array(
            'string' => array(
                'workflowIdentifier' => self::TEST_WORKFLOW_NAME,
            ),
            'workflow item' => array(
                'workflowIdentifier' => $this->createWorkflowItem(self::TEST_WORKFLOW_NAME),
            ),
            'workflow' => array(
                'workflowIdentifier' => $this->createWorkflow(self::TEST_WORKFLOW_NAME),
            ),
        );
    }

    /**
     * @expectedException \Oro\Bundle\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage Can't find workflow by given identifier.
     */
    public function testGetWorkflowCantFind()
    {
        $incorrectIdentifier = null;
        $this->workflowManager->getWorkflow($incorrectIdentifier);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    protected function createEntityManager()
    {
        return $this->getMockBuilder('Doctrine\Orm\EntityManager')
            ->disableOriginalConstructor()
            ->setMethods(array('beginTransaction', 'persist', 'flush', 'commit', 'rollback'))
            ->getMock();
    }

    /**
     * @param string $workflowName
     * @return WorkflowItem
     */
    protected function createWorkflowItem($workflowName = self::TEST_WORKFLOW_NAME)
    {
        $workflowItem = new WorkflowItem();
        $workflowItem->setWorkflowName($workflowName);

        return $workflowItem;
    }

    /**
     * @param string $name
     * @param array $entityAttributes
     * @param array $startTransitions
     * @return Workflow|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function createWorkflow(
        $name = self::TEST_WORKFLOW_NAME,
        array $entityAttributes = array(),
        array $startTransitions = array()
    ) {
        $attributeManager = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\AttributeManager')
            ->setMethods(array('getManagedEntityAttributes'))
            ->getMock();
        $attributeManager->expects($this->any())
            ->method('getManagedEntityAttributes')
            ->will($this->returnValue($entityAttributes));

        $transitionManager = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\TransitionManager')
            ->setMethods(array('getStartTransitions'))
            ->getMock();
        $transitionManager->expects($this->any())
            ->method('getStartTransitions')
            ->will($this->returnValue(new ArrayCollection($startTransitions)));

        $entityConnector = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\EntityConnector')
            ->disableOriginalConstructor()
            ->getMock();
        $aclManager = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Acl\AclManager')
            ->disableOriginalConstructor()
            ->getMock();

        $worklflow = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\Workflow')
            ->setConstructorArgs(array($entityConnector, $aclManager, null, $attributeManager, $transitionManager))
            ->setMethods(
                array(
                    'isTransitionAvailable',
                    'isStartTransitionAvailable',
                    'getTransitionsByWorkflowItem',
                    'start',
                    'transit'
                )
            )
            ->getMock();

        /** @var Workflow $worklflow */
        $worklflow->setName($name);

        return $worklflow;
    }

    public function trueFalseDataProvider()
    {
        return array(
            array(true),
            array(false)
        );
    }

    /**
     * @param bool $result
     * @dataProvider trueFalseDataProvider
     */
    public function testHasApplicableWorkflowByEntityClass($result)
    {
        $entityClass = 'TestEntity';

        $this->workflowRegistry->expects($this->once())
            ->method('hasActiveWorkflowByEntityClass')
            ->with($entityClass)
            ->will($this->returnValue($result));

        $this->assertEquals($result, $this->workflowManager->hasApplicableWorkflowByEntityClass($entityClass));
    }

    public function activateWorkflowDataProvider()
    {
        $workflowDefinition = new WorkflowDefinition();
        $workflowDefinition->setName('test_workflow');
        $workflowDefinition->setRelatedEntity('\DateTime');

        return array(
            'by workflow name' => array(
                'workflow_identifier' => 'test_workflow'
            ),
            'by workflow definition' => array(
                'workflow_identifier' => $workflowDefinition
            ),
        );
    }

    /**
     * @param mixed $workflowIdentifier
     * @dataProvider activateWorkflowDataProvider
     */
    public function testActivateWorkflow($workflowIdentifier)
    {
        if ($workflowIdentifier instanceof WorkflowDefinition) {
            $workflowName = $workflowIdentifier->getName();
            $entityClass = $workflowIdentifier->getRelatedEntity();
        } else {
            $workflowName = $workflowIdentifier;
            $entityClass = '\DateTime';
            $workflowDefinition = new WorkflowDefinition();
            $workflowDefinition->setRelatedEntity($entityClass);
            /** @var Workflow $worklflow */
            $worklflow = $this->getMockBuilder('Oro\Bundle\WorkflowBundle\Model\Workflow')
                ->disableOriginalConstructor()
                ->setMethods(null)
                ->getMock();
            $worklflow->setName($workflowName);
            $worklflow->setDefinition($workflowDefinition);
            $this->workflowRegistry->expects($this->once())->method('getWorkflow')->with($workflowIdentifier)
                ->will($this->returnValue($worklflow));
        }

        $entityConfig = $this->getMock('Oro\Bundle\EntityConfigBundle\Config\ConfigInterface');
        $entityConfig->expects($this->once())->method('set')->with('active_workflow', $workflowName);

        $workflowConfigProvider = $this->getMock('Oro\Bundle\EntityConfigBundle\Provider\ConfigProviderInterface');
        $workflowConfigProvider->expects($this->once())->method('hasConfig')->with($entityClass)
            ->will($this->returnValue(true));
        $workflowConfigProvider->expects($this->once())->method('getConfig')->with($entityClass)
            ->will($this->returnValue($entityConfig));

        $this->configManager->expects($this->once())->method('getProvider')->with('workflow')
            ->will($this->returnValue($workflowConfigProvider));
        $this->configManager->expects($this->once())->method('persist')->with($entityConfig);
        $this->configManager->expects($this->once())->method('flush');

        $this->workflowManager->activateWorkflow($workflowIdentifier);
    }

    public function testDeactivateWorkflow()
    {
        $entityClass = '\DateTime';

        $entityConfig = $this->getMock('Oro\Bundle\EntityConfigBundle\Config\ConfigInterface');
        $entityConfig->expects($this->once())->method('set')->with('active_workflow', null);

        $workflowConfigProvider = $this->getMock('Oro\Bundle\EntityConfigBundle\Provider\ConfigProviderInterface');
        $workflowConfigProvider->expects($this->once())->method('hasConfig')->with($entityClass)
            ->will($this->returnValue(true));
        $workflowConfigProvider->expects($this->once())->method('getConfig')->with($entityClass)
            ->will($this->returnValue($entityConfig));

        $this->configManager->expects($this->once())->method('getProvider')->with('workflow')
            ->will($this->returnValue($workflowConfigProvider));
        $this->configManager->expects($this->once())->method('persist')->with($entityConfig);
        $this->configManager->expects($this->once())->method('flush');

        $this->workflowManager->deactivateWorkflow($entityClass);
    }

    /**
     * @expectedException \Oro\Bundle\WorkflowBundle\Exception\WorkflowException
     * @expectedExceptionMessage Entity \DateTime is not configurable
     */
    public function testNotConfigurableEntityException()
    {
        $entityClass = '\DateTime';

        $workflowConfigProvider = $this->getMock('Oro\Bundle\EntityConfigBundle\Provider\ConfigProviderInterface');
        $workflowConfigProvider->expects($this->once())->method('hasConfig')->with($entityClass)
            ->will($this->returnValue(false));
        $workflowConfigProvider->expects($this->never())->method('getConfig');

        $this->configManager->expects($this->once())->method('getProvider')->with('workflow')
            ->will($this->returnValue($workflowConfigProvider));

        $this->workflowManager->deactivateWorkflow($entityClass);
    }
}
