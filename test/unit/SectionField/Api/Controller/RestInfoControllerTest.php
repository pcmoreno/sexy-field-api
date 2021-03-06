<?php
declare (strict_types=1);

namespace Tardigrades\SectionField\Api\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Guzzle\Http\Message\Header\HeaderCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tardigrades\Entity\Field;
use Tardigrades\Entity\FieldType;
use Tardigrades\Entity\SectionInterface;
use Tardigrades\FieldType\Relationship\Relationship;
use Tardigrades\SectionField\Api\Serializer\SerializeToArrayInterface;
use Tardigrades\SectionField\Event\ApiBeforeEntrySavedAfterValidated;
use Tardigrades\SectionField\Event\ApiBeforeEntryUpdatedAfterValidated;
use Tardigrades\SectionField\Event\ApiCreateEntry;
use Tardigrades\SectionField\Event\ApiDeleteEntry;
use Tardigrades\SectionField\Event\ApiEntriesFetched;
use Tardigrades\SectionField\Event\ApiEntryCreated;
use Tardigrades\SectionField\Event\ApiEntryDeleted;
use Tardigrades\SectionField\Event\ApiEntryFetched;
use Tardigrades\SectionField\Event\ApiEntryUpdated;
use Tardigrades\SectionField\Event\ApiUpdateEntry;
use Tardigrades\SectionField\Form\FormInterface;
use Symfony\Component\Form\FormInterface as SymfonyFormInterface;
use Tardigrades\SectionField\Generator\CommonSectionInterface;
use Tardigrades\SectionField\Service\CreateSectionInterface;
use Tardigrades\SectionField\Service\DeleteSectionInterface;
use Tardigrades\SectionField\Service\EntryNotFoundException;
use Tardigrades\SectionField\Service\ReadOptions;
use Tardigrades\SectionField\Service\ReadSectionInterface;
use Tardigrades\SectionField\Service\SectionManagerInterface;
use Mockery;
use Tardigrades\SectionField\Service\SectionNotFoundException;
use Tardigrades\SectionField\ValueObject\Handle;
use Tardigrades\SectionField\ValueObject\Name;
use Tardigrades\SectionField\ValueObject\SectionConfig;

/**
 * @coversDefaultClass Tardigrades\SectionField\Api\Controller\RestInfoController
 *
 * @covers ::<private>
 * @covers ::<protected>
 */
class RestInfoControllerTest extends TestCase
{
    use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

    /** @var ReadSectionInterface|Mockery\Mock */
    private $readSection;

    /** @var CreateSectionInterface|Mockery\Mock */
    private $createSection;

    /** @var DeleteSectionInterface|Mockery\Mock */
    private $deleteSection;

    /** @var FormInterface|Mockery\Mock */
    private $form;

    /** @var SectionManagerInterface|Mockery\Mock */
    private $sectionManager;

    /** @var RequestStack|Mockery\Mock */
    private $requestStack;

    /** @var EventDispatcherInterface|Mockery\Mock */
    private $dispatcher;

    /** @var SerializeToArrayInterface|Mockery\MockInterface */
    private $serialize;

    /** @var RestInfoController */
    private $controller;

    public function setUp()
    {
        $this->readSection = Mockery::mock(ReadSectionInterface::class);
        $this->requestStack = Mockery::mock(RequestStack::class);
        $this->createSection = Mockery::mock(CreateSectionInterface::class);
        $this->deleteSection = Mockery::mock(DeleteSectionInterface::class);
        $this->form = Mockery::mock(FormInterface::class);
        $this->sectionManager = Mockery::mock(SectionManagerInterface::class);
        $this->dispatcher = Mockery::mock(EventDispatcherInterface::class);
        $this->serialize = Mockery::mock(SerializeToArrayInterface::class);

        $this->controller = new RestInfoController(
            $this->createSection,
            $this->readSection,
            $this->deleteSection,
            $this->form,
            $this->sectionManager,
            $this->requestStack,
            $this->dispatcher,
            $this->serialize
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     */
    public function it_returns_options_listings()
    {
        $allowedMethods = 'OPTIONS, GET, POST, PUT, DELETE';
        $testCases = [
            // method name,    arguments,      allowed HTTP methods
            ['getSectionInfo', ['foo', "0"], $allowedMethods]
        ];
        foreach ($testCases as [$method, $args, $allowMethods]) {
            $request = Mockery::mock(Request::class);
            $request->shouldReceive('getMethod')
                ->andReturn('options');
            $this->requestStack->shouldReceive('getCurrentRequest')
                ->once()
                ->andReturn($request);

            $request->headers = Mockery::mock(HeaderCollection::class);
            $request->headers->shouldReceive('get')
                ->with('Origin')
                ->once()
                ->andReturn('someorigin.com');

            $response = new JsonResponse([], JsonResponse::HTTP_OK, [
                'Access-Control-Allow-Origin' => 'someorigin.com',
                'Access-Control-Allow-Methods' => $allowMethods,
                'Access-Control-Allow-Credentials' => 'true'
            ]);
            $this->assertEquals($this->controller->$method(...$args), $response);
        }
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     */
    public function it_gets_section_info_of_a_section_without_relationships()
    {
        $sectionName = 'Sexy';
        $sectionHandle = 'sexyHandle';
        $section = Mockery::mock(SectionInterface::class);
        $expectedFieldInfo = [
            'name' => $sectionName,
            'handle' => $sectionHandle
        ];

        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();
        $mockedForm->shouldReceive('getData')
            ->once()
            ->andReturn($entryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->once()
            ->with($sectionHandle, $this->requestStack, null, false)
            ->andReturn($mockedForm);

        $this->sectionManager->shouldReceive('readByHandle')
            ->once()
            ->andReturn($section);

        $section->shouldReceive('getName')
            ->once()
            ->andReturn(Name::fromString($sectionName));

        $section->shouldReceive('getHandle')
            ->once()
            ->andReturn(Handle::fromString($sectionHandle));

        $section->shouldReceive('getFields')
            ->once()
            ->andReturn($this->givenASetOfFieldsForASection());

        $fields = $this->givenASetOfFieldInfo();

        $sectionConfig = SectionConfig::fromArray([
            'section' => [
                'name' => 'Some section',
                'handle' => 'Some handle',
                'fields' => [
                    'someHandle',
                    'someOtherHandle'
                ],
                'default' => 'default',
                'namespace' => 'NameSpace'
            ]
        ]);
        $section->shouldReceive('getConfig')
            ->once()
            ->andReturn($sectionConfig);

        $expectedFieldInfo['fields'] = $fields;

        $expectedFieldInfo = array_merge($expectedFieldInfo, $sectionConfig->toArray());

        $expectedResponse = new JsonResponse($expectedFieldInfo, 200, [
            'Access-Control-Allow-Origin' => 'iamtheorigin.com',
            'Access-Control-Allow-Credentials' => 'true'
        ]);

        $response = $this->controller->getSectionInfo('sexyHandle');
        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     */
    public function it_does_not_find_sections()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $this->sectionManager->shouldReceive('readByHandle')
            ->once()
            ->andThrow(SectionNotFoundException::class);

        $expectedResponse = new JsonResponse(['message' => 'Section not found'], 404, [
            'Access-Control-Allow-Origin' => 'iamtheorigin.com',
            'Access-Control-Allow-Credentials' => 'true'
        ]);

        $response = $this->controller->getSectionInfo('foo');
        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     */
    public function it_fails_finding_sections_for_another_reason()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $this->sectionManager->shouldReceive('readByHandle')
            ->once()
            ->andThrow(\Exception::class, "Uh-oh");

        $expectedResponse = new JsonResponse(['message' => 'Uh-oh'], 400, [
            'Access-Control-Allow-Origin' => 'iamtheorigin.com',
            'Access-Control-Allow-Credentials' => 'true'
        ]);

        $response = $this->controller->getSectionInfo('foo');
        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     * @runInSeparateProcess
     */
    public function it_gets_section_info_of_a_section_with_relationships()
    {
        $sectionName = 'Even more sexy';
        $sectionHandle = 'evenMoreSexy';
        $section = Mockery::mock(SectionInterface::class);

        $expectedFieldInfo = [
            'name' => $sectionName,
            'handle' => $sectionHandle
        ];

        $request = new Request([
            'options' => 'someRelationshipFieldHandle|limit:100|offset:0'
        ], [], [], [], [], [
            'HTTP_ORIGIN' => 'iamtheorigin.com'
        ]);

        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();
        $mockedForm->shouldReceive('getData')
            ->once()
            ->andReturn($entryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->once()
            ->andReturn($mockedForm);

        $this->sectionManager->shouldReceive('readByHandle')
            ->once()
            ->andReturn($section);

        $section->shouldReceive('getName')
            ->once()
            ->andReturn(Name::fromString($sectionName));

        $section->shouldReceive('getHandle')
            ->once()
            ->andReturn(Handle::fromString($sectionHandle));

        $section->shouldReceive('getFields')
            ->once()
            ->andReturn($this->givenASetOfFieldsForASection(true));

        $sectionConfig = SectionConfig::fromArray([
            'section' => [
                'name' => 'Some section',
                'handle' => 'Some handle',
                'fields' => [
                    'someHandle',
                    'someOtherHandle',
                    'someRelationshipFieldHandle'
                ],
                'default' => 'default',
                'namespace' => 'NameSpace',
                'sexy-field-instructions' => ['relationship' => 'getName']
            ]
        ]);
        $section->shouldReceive('getConfig')
            ->once()
            ->andReturn($sectionConfig);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $sectionEntitiesTo = new \ArrayIterator();
        $formattedRecords = $this->givenSomeFormattedToRecords();

        foreach ($formattedRecords as $formattedRecord) {
            $section = Mockery::mock(CommonSectionInterface::class);
            $otherSection = Mockery::mock(CommonSectionInterface::class);
            $yetAnotherSection = Mockery::mock(CommonSectionInterface::class);

            $section->shouldReceive('getFoo')
                ->once()
                ->andReturn($otherSection);

            $otherSection->shouldReceive('getBar')
                ->once()
                ->andReturn($yetAnotherSection);

            $yetAnotherSection->shouldReceive('getName')
                ->once()
                ->andReturn($formattedRecord['name']);

            $section->shouldReceive('getId')
                ->once()
                ->andReturn($formattedRecord['id']);

            $section->shouldReceive('getSlug')
                ->once()
                ->andReturn($formattedRecord['slug']);

            $section->shouldReceive('getDefault')
                ->once()
                ->andReturn($formattedRecord['name']);

            $section->shouldReceive('getCreated')
                ->once()
                ->andReturn($formattedRecord['created']);

            $section->shouldReceive('getUpdated')
                ->once()
                ->andReturn($formattedRecord['updated']);

            $sectionEntitiesTo->append($section);
        }

        $expectedFieldInfo['fields'] = $this->givenASetOfFieldInfo(true);
        $expectedFieldInfo['fields'][2]['someRelationshipFieldHandle']['whatever'] = $formattedRecords;

        $expectedFieldInfo = array_merge($expectedFieldInfo, $sectionConfig->toArray());

        $expectedResponse = new JsonResponse(
            $expectedFieldInfo,
            200,
            [
                'Access-Control-Allow-Origin' => 'iamtheorigin.com',
                'Access-Control-Allow-Credentials' => 'true'
            ]
        );

        $this->readSection->shouldReceive('read')->andReturn($sectionEntitiesTo);

        $response = $this->controller->getSectionInfo('sexyHandle');

        $this->assertEquals($expectedResponse, $response);
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getSectionInfo
     * @runInSeparateProcess
     */
    public function it_fails_getting_section_info_of_a_section_with_relationships()
    {
        $sectionName = 'Even more sexy';
        $sectionHandle = 'evenMoreSexy';
        $section = Mockery::mock(SectionInterface::class);

        $expectedFieldInfo = [
            'name' => $sectionName,
            'handle' => $sectionHandle
        ];

        $request = new Request([], [], [], [], [], [
            'HTTP_ORIGIN' => 'iamtheorigin.com'
        ]);

        $entryMock = Mockery::mock(CommonSectionInterface::class);
        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();
        $mockedForm->shouldReceive('getData')
            ->once()
            ->andReturn($entryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->once()
            ->andReturn($mockedForm);

        $this->sectionManager->shouldReceive('readByHandle')
            ->once()
            ->andReturn($section);

        $section->shouldReceive('getName')
            ->once()
            ->andReturn(Name::fromString($sectionName));

        $section->shouldReceive('getHandle')
            ->once()
            ->andReturn(Handle::fromString($sectionHandle));

        $section->shouldReceive('getFields')
            ->once()
            ->andReturn($this->givenASetOfFieldsForASection(true));

        $sectionConfig = SectionConfig::fromArray([
            'section' => [
                'name' => 'Some section',
                'handle' => 'Some handle',
                'fields' => [
                    'someHandle',
                    'someOtherHandle',
                    'someRelationshipFieldHandle'
                ],
                'default' => 'default',
                'namespace' => 'NameSpace'
            ]
        ]);
        $section->shouldReceive('getConfig')
            ->once()
            ->andReturn($sectionConfig);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $expectedFieldInfo['fields'] = $this->givenASetOfFieldInfo(true);
        $expectedFieldInfo['fields'][2]['someRelationshipFieldHandle']['whatever'] = ['error' => 'Entry not found'];

        $expectedFieldInfo = array_merge($expectedFieldInfo, $sectionConfig->toArray());


        $this->readSection->shouldReceive('read')->andThrow(EntryNotFoundException::class);

        $response = $this->controller->getSectionInfo('sexyHandle');
        $expectedResponse = new JsonResponse(
            $expectedFieldInfo,
            200,
            [
                'Access-Control-Allow-Origin' => 'iamtheorigin.com',
                'Access-Control-Allow-Credentials' => 'true'
            ]
        );

        $this->assertEquals($expectedResponse, $response);
    }

    private function givenASetOfFieldsForASection(bool $includeRelationships = false): Collection
    {
        $fields = new ArrayCollection();

        $fields->add(
            (new Field())
                ->setId(1)
                ->setConfig([
                    'field' => [
                        'name' => 'Fieldje',
                        'handle' => 'fieldje'
                    ]
                ])
                ->setHandle('someHandle')
                ->setFieldType(
                    (new FieldType())
                        ->setFullyQualifiedClassName('Some\\Fully\\Qualified\\Classname')
                        ->setType('TextInput')
                        ->setId(1)
                )
                ->setName('Some name field')
        );

        $fields->add(
            (new Field())
                ->setId(2)
                ->setConfig([
                    'field' => [
                        'name' => 'Nog een fieldje',
                        'handle' => 'nogEenFieldje'
                    ]
                ])
                ->setHandle('someOtherHandle')
                ->setFieldType(
                    (new FieldType())
                        ->setFullyQualifiedClassName('I\\Am\\The\\Fully\\Qualified\\Classname')
                        ->setType('TextInput')
                        ->setId(2)
                )
                ->setName('Give me text')
        );

        if ($includeRelationships) {
            $fields->add(
                (new Field())
                    ->setId(3)
                    ->setConfig([
                        'field' => [
                            'name' => 'Relatie veld',
                            'handle' => 'someRelationshipFieldHandle',
                            'to' => 'whatever',
                            'form' => [
                                'sexy-field-instructions' => [
                                    'relationship' => [
                                        'name-expression' => 'getFoo|getBar|getName',
                                        'limit' => 75,
                                        'offset' => 10,
                                        'field' => 'foo',
                                        'value' => 'bar,baz'
                                    ]
                                ]
                            ]
                        ]
                    ])
                    ->setHandle('someRelationshipFieldHandle')
                    ->setFieldType(
                        (new FieldType())
                            ->setFullyQualifiedClassName(Relationship::class)
                            ->setType('Relationship')
                            ->setId(3)
                    )
                    ->setName('Relatie veld')
            );
        }

        return $fields;
    }

    private function givenSomeFormattedToRecords(): array
    {
        return [
            [
                'id' => 1,
                'slug' => 'sleepy-sluggg',
                'name' => 'Sleepy Slugg',
                'created' => new \DateTime(),
                'updated' => new \DateTime(),
                'selected' => false
            ],
            [
                'id' => 2,
                'slug' => 'some-slug-slack',
                'name' => 'Some slack slug',
                'created' => new \DateTime(),
                'updated' => new \DateTime(),
                'selected' => false
            ],
            [
                'id' => 3,
                'slug' => 'slack-slug-slog',
                'name' => 'Slack slug slog',
                'created' => new \DateTime(),
                'updated' => new \DateTime(),
                'selected' => false
            ]
        ];
    }

    private function givenASetOfFieldInfo(bool $includeRelationships = false): array
    {
        $fieldInfos = [];
        $fields = $this->givenASetOfFieldsForASection($includeRelationships);

        foreach ($fields as $field) {
            $fieldInfo = [
                (string)$field->getHandle() => $field->getConfig()->toArray()['field']
            ];

            $fieldInfos[] = $fieldInfo;
        }

        return $fieldInfos;
    }
}
