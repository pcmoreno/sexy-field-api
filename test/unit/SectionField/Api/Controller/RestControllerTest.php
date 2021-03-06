<?php
declare (strict_types=1);

namespace Tardigrades\SectionField\Api\Controller;

use Guzzle\Http\Message\Header\HeaderCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
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

/**
 * @coversDefaultClass \Tardigrades\SectionField\Api\Controller\RestController
 *
 * @covers ::<private>
 * @covers ::<protected>
 */
class RestControllerTest extends TestCase
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

    /** @var RestController */
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

        $this->controller = new RestController(
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
     * @covers ::getEntryById
     * @covers ::getEntryBySlug
     * @covers ::getEntriesByFieldValue
     * @covers ::getEntries
     * @covers ::createEntry
     * @covers ::updateEntryById
     * @covers ::updateEntryBySlug
     * @covers ::deleteEntryById
     * @covers ::deleteEntryBySlug
     */
    public function it_returns_options_listings()
    {
        $allowedMethods = 'OPTIONS, GET, POST, PUT, DELETE';
        $testCases = [
            // method name,    arguments,      allowed HTTP methods
            ['getEntryById', ['foo', "0"], $allowedMethods],
            ['getEntryBySlug', ['foo', 'bar'], $allowedMethods],
            ['getEntriesByFieldValue', ['foo', 'bar'], $allowedMethods],
            ['getEntries', ['foo'], $allowedMethods],
            ['createEntry', ['foo'], $allowedMethods],
            ['updateEntryById', ['foo', 0], $allowedMethods],
            ['updateEntryBySlug', ['foo', 'bar'], $allowedMethods],
            ['deleteEntryById', ['foo', 0], $allowedMethods],
            ['deleteEntryBySlug', ['foo', 'bar'], $allowedMethods]
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
     * @covers ::getEntryById
     * @covers ::getEntryBySlug
     * @covers ::getEntriesByFieldValue
     * @covers ::getEntries
     * @covers ::deleteEntryById
     * @covers ::deleteEntryBySlug
     * @covers ::updateEntryById
     * @covers ::updateEntryBySlug
     */
    public function it_does_not_find_entries()
    {
        $testCases = [
            // method name,  arguments,     GET query, expect dispatch, expect build form
            ['getEntryById', ['foo', '10'], [], false, false],
            ['getEntryBySlug', ['foo', 'bar'], [], false, false],
            ['getEntriesByFieldValue', ['foo', 'bar'], ['value' => 23], false, false],
            ['getEntries', ['foo'], [], false, false],
            ['deleteEntryById', ['foo', 12], [], true, false],
            ['deleteEntryBySlug', ['foo', 'bar'], [], true, false],
            ['updateEntryById', ['foo', 13], [], true, true],
            ['updateEntryBySlug', ['foo', 'bar'], [], true, true]
        ];
        foreach ($testCases as [$method, $args, $query, $expectDispatch, $expectBuildForm]) {
            $request = new Request($query, [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

            $this->requestStack->shouldReceive('getCurrentRequest')->andReturn($request);
            $this->readSection->shouldReceive('read')
                ->once()
                ->andThrow(EntryNotFoundException::class);


            if ($expectDispatch) {
                $this->dispatcher->shouldReceive('dispatch')->once();
            }
            if ($expectBuildForm) {
                $this->form->shouldReceive('buildFormForSection')->once();
            }

            $response = $this->controller->$method(...$args);
            $expectedResponse = new JsonResponse([
                'message' => 'Entry not found'
            ], 404, [
                'Access-Control-Allow-Origin' => 'iamtheorigin.com',
                'Access-Control-Allow-Credentials' => 'true'
            ]);
            $this->assertEquals($expectedResponse, $response);
        }
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getEntryById
     * @covers ::getEntryBySlug
     * @covers ::getEntriesByFieldValue
     * @covers ::getEntries
     * @covers ::deleteEntryBySlug
     * @covers ::deleteEntryById
     */
    public function it_fails_getting_entries_while_reading()
    {
        $testCases = [
            // method name,  arguments,     GET query, expect dispatch
            ['getEntryById', ['foo', '10'], [],        false],
            ['getEntryBySlug', ['foo', 'bar'], [], false],
            ['getEntriesByFieldValue', ['foo', 'bar'], ['value' => 23], false],
            ['getEntries', ['foo'], [], false],
            ['deleteEntryBySlug', ['foo', 'bar'], [], true],
            ['deleteEntryById', ['foo', 247], [], true]
        ];
        foreach ($testCases as [$method, $args, $query, $expectDispatch]) {
            $request = new Request($query, [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

            $this->requestStack->shouldReceive('getCurrentRequest')
                ->once()
                ->andReturn($request);

            $this->readSection->shouldReceive('read')
                ->once()
                ->andThrow(\Exception::class, "Something exceptional happened");


            $expectedResponse = new JsonResponse(['message' => "Something exceptional happened"], 400, [
                'Access-Control-Allow-Origin' => 'iamtheorigin.com',
                'Access-Control-Allow-Credentials' => 'true'
            ]);

            if ($expectDispatch) {
                $this->dispatcher->shouldReceive('dispatch')->once();
            }

            $response = $this->controller->$method(...$args);
            $this->assertEquals($expectedResponse, $response);
        }
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::createEntry
     * @covers ::updateEntryById
     * @covers ::updateEntryBySlug
     */
    public function it_fails_getting_entries_while_building_a_form()
    {
        $testCases = [
            // method name,  arguments,     GET query, expect dispatch
            ['createEntry', ['foo'], ['baz' => 'bat'], true],
            ['updateEntryById', ['foo', 14], [], true],
            ['updateEntryBySlug', ['foo', 'bar'], [], true]
        ];
        foreach ($testCases as [$method, $args, $query, $expectDispatch]) {
            $request = new Request($query, [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

            $this->requestStack->shouldReceive('getCurrentRequest')
                ->andReturn($request);

            $this->form->shouldReceive('buildFormForSection')
                ->once()
                ->andThrow(\Exception::class, "Something exceptional happened");

            $expectedResponse = new JsonResponse(['message' => "Something exceptional happened"], 400, [
                'Access-Control-Allow-Origin' => 'iamtheorigin.com',
                'Access-Control-Allow-Credentials' => 'true'
            ]);

            if ($expectDispatch) {
                $this->dispatcher->shouldReceive('dispatch')->once();
            }

            $response = $this->controller->$method(...$args);
            $this->assertEquals($expectedResponse, $response);
        }
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getEntryById
     * @covers ::getEntryBySlug
     * @covers \Tardigrades\SectionField\Api\Serializer\DepthExclusionStrategy
     */
    public function it_should_get_entry_by_id()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->serialize->shouldReceive('toArray')->twice();

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($request);

        $this->readSection
            ->shouldReceive('read')
            ->andReturn(new \ArrayIterator([Mockery::mock(CommonSectionInterface::class)]));

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([
                ApiEntryFetched::NAME,
                Mockery::type(ApiEntryFetched::class)
            ]);

        $response = $this->controller->getEntryById('sexyHandle', '90000');
        $this->assertSame('[]', $response->getContent());


        $response = $this->controller->getEntryBySlug('sexyHandle', 'slug');
        $this->assertSame('[]', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getEntriesByFieldValue
     * @covers \Tardigrades\SectionField\Api\Serializer\DepthExclusionStrategy
     */
    public function it_should_get_entries_by_field_value()
    {
        $sectionHandle = 'rockets';
        $fieldHandle = 'uuid';
        $fieldValue = '719d72d7-4f0c-420b-993f-969af9ad34c1';
        $offset = 0;
        $limit = 100;
        $orderBy = 'name';
        $sort = 'desc';

        $request = new Request([
            'value' => $fieldValue,
            'offset' => $offset,
            'limit' => $limit,
            'orderBy' => $orderBy,
            'sort' => $sort,
            'fields' => ['id']
        ]);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $this->readSection->shouldReceive('read')
            ->once()
            ->andReturn(
                new \ArrayIterator([
                    Mockery::mock(CommonSectionInterface::class),
                    Mockery::mock(CommonSectionInterface::class)
                ])
            );

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiEntriesFetched::NAME,
                Mockery::type(ApiEntriesFetched::class)
            ]);

        $this->serialize->shouldReceive('toArray')->twice();

        $response = $this->controller->getEntriesByFieldValue($sectionHandle, $fieldHandle);

        $this->assertSame('[[],[]]', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getEntriesByFieldValue
     * @covers \Tardigrades\SectionField\Api\Serializer\DepthExclusionStrategy
     */
    public function it_should_get_entries_by_multiple_field_values()
    {
        $sectionHandle = 'rockets';
        $fieldHandle = 'uuid';
        $fieldValue = '719d72d7-4f0c-420b-993f-969af9ad34c1,9d716145-eef6-442c-acea-93acf3990b6d';
        $offset = 0;
        $limit = 100;
        $orderBy = 'name';
        $sort = 'desc';

        $this->serialize->shouldReceive('toArray')->twice();

        $request = new Request([
            'value' => $fieldValue,
            'offset' => $offset,
            'limit' => $limit,
            'orderBy' => $orderBy,
            'sort' => $sort,
            'fields' => ['id']
        ]);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiEntriesFetched::NAME,
                Mockery::type(ApiEntriesFetched::class)
            ]);

        $this->readSection->shouldReceive('read')
            ->andReturn(new \ArrayIterator([
                Mockery::mock(CommonSectionInterface::class),
                Mockery::mock(CommonSectionInterface::class)
            ]));

        $response = $this->controller->getEntriesByFieldValue($sectionHandle, $fieldHandle);

        $this->assertSame('[[],[]]', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::getEntries
     * @covers \Tardigrades\SectionField\Api\Serializer\DepthExclusionStrategy
     */
    public function it_should_get_the_entries()
    {
        $mockRequest = Mockery::mock(Request::class)->makePartial();
        $mockRequest->shouldReceive('getMethod')
            ->once()
            ->andReturn('NOT_OPTIONS');

        $mockRequest->shouldReceive('get')
            ->with('offset', 0)
            ->andReturn(10);

        $mockRequest->shouldReceive('get')
            ->with('limit', 100)
            ->andReturn(1);

        $mockRequest->shouldReceive('get')
            ->with('orderBy', 'created')
            ->andReturn('name');

        $mockRequest->shouldReceive('get')
            ->with('sort', 'DESC')
            ->andReturn('DESC');

        $mockRequest->shouldReceive('get')
            ->with('fields', ['id'])
            ->andReturn('');

        $mockRequest->shouldReceive('get')
            ->with('fields', null)
            ->andReturn(['id']);

        $mockRequest->shouldReceive('get')
            ->with('depth', 20)
            ->andReturn(20);

        $mockRequest->headers = Mockery::mock(HeaderCollection::class);
        $mockRequest->headers->shouldReceive('get')
            ->with('Origin')
            ->once()
            ->andReturn('someorigin.com');

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($mockRequest);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiEntriesFetched::NAME,
                Mockery::type(ApiEntriesFetched::class)
            ])
        ;

        $this->readSection->shouldReceive('read')
            ->andReturn(new \ArrayIterator([
                    Mockery::mock(CommonSectionInterface::class),
                    Mockery::mock(CommonSectionInterface::class)
                ])
            );

        $this->serialize->shouldReceive('toArray')->twice();

        $response = $this->controller->getEntries('sexy');

        $this->assertSame('[[],[]]', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::createEntry
     */
    public function it_creates_an_entry()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack
            ->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();

        $mockedForm->shouldReceive('submit')->once();
        $mockedForm->shouldReceive('getName')->once();
        $mockedForm->shouldReceive('isValid')
            ->andReturn(true);
        $mockedForm->shouldReceive('getData')
            ->andReturn($entryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->with('sexy', $this->requestStack, false, false)
            ->andReturn($mockedForm);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiCreateEntry::NAME,
                Mockery::type(ApiCreateEntry::class)
            ]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiBeforeEntrySavedAfterValidated::NAME,
                Mockery::type(ApiBeforeEntrySavedAfterValidated::class)
            ]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiEntryCreated::NAME,
                Mockery::type(ApiEntryCreated::class)
            ]);

        $mockedRequest = Mockery::mock(Request::class)->makePartial();
        $mockedRequest->shouldReceive('get')
            ->with('form')
            ->andReturn(['no']);

        $this->createSection->shouldReceive('save')
            ->with($entryMock)
            ->once()
            ->andReturn(true);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($mockedRequest);

        $this->serialize->shouldReceive('toArray')->once();

        $response = $this->controller->createEntry('sexy');
        $this->assertSame(
            '{"code":200,"success":true,"errors":false,"entry":[]}',
            $response->getContent()
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::createEntry
     */
    public function it_fails_creating_an_entry_during_save_and_returns_the_correct_response()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->once()
            ->andReturn($request);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiCreateEntry::NAME,
                Mockery::type(ApiCreateEntry::class)
            ]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiBeforeEntrySavedAfterValidated::NAME,
                Mockery::type(ApiBeforeEntrySavedAfterValidated::class)
            ]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiEntryCreated::NAME,
                Mockery::type(ApiEntryCreated::class)
            ]);

        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();

        $mockedForm->shouldReceive('submit')->once();
        $mockedForm->shouldReceive('getName')->once();
        $mockedForm->shouldReceive('isValid')->andReturn(true);
        $mockedForm->shouldReceive('getData')
            ->andReturn($entryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->with('sexy', $this->requestStack, false, false)
            ->once()
            ->andReturn($mockedForm);

        $mockedRequest = Mockery::mock(Request::class)->makePartial();
        $mockedRequest->shouldReceive('get')->with('form')
            ->andReturn(['no']);

        $this->createSection->shouldReceive('save')
            ->with($entryMock)
            ->once()
            ->andThrow(\Exception::class, "Something woeful occurred");

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($mockedRequest);

        $response = $this->controller->createEntry('sexy');
        $this->assertSame(
            '{"code":500,"exception":"Something woeful occurred"}',
            $response->getContent()
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::createEntry
     */
    public function it_does_not_create_an_entry_and_returns_correct_response()
    {
        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();

        $mockedForm->shouldReceive('submit')->once();
        $mockedForm->shouldReceive('getName')->once();
        $mockedForm->shouldReceive('isValid')->andReturn(false);
        $mockedForm->shouldReceive('getName')->andReturn('name of form');
        $mockedForm->shouldReceive('getIterator')->andReturn(new \ArrayIterator([$mockedForm]));

        $error = Mockery::mock(FormError::class)->makePartial();
        $error->shouldReceive('getMessage')->andReturn('you are wrong!');
        $mockedForm->shouldReceive('getErrors')
            ->andReturn([$error]);

        $this->dispatcher->shouldReceive('dispatch')
            ->once()
            ->withArgs([
                ApiCreateEntry::NAME,
                Mockery::type(ApiCreateEntry::class)
            ]);

        $this->form->shouldReceive('buildFormForSection')
            ->andReturn($mockedForm);

        $mockedRequest = Mockery::mock(Request::class)->makePartial();
        $mockedRequest->shouldReceive('get')->twice();
        $mockedRequest->shouldReceive('getMethod')
            ->andReturn('not options');

        $mockedRequest->headers = Mockery::mock(HeaderBag::class);
        $mockedRequest->headers
            ->shouldReceive('get')
            ->with('Origin')
            ->andReturn('Some origin');

        $this->createSection
            ->shouldReceive('save')
            ->never();

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($mockedRequest);

        $response = $this->controller->createEntry('sexy');

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            '{"code":400,"errors":{"0":"you are wrong!","name of form":["you are wrong!"]}}',
            $response->getContent()
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::updateEntryById
     * @covers ::updateEntryBySlug
     */
    public function it_updates_entries()
    {
        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->times(4)
            ->andReturn($request);

        $this->serialize->shouldReceive('toArray')->twice();

        $originalEntryMock = Mockery::mock(CommonSectionInterface::class);
        $iteratorMock = Mockery::mock(\ArrayIterator::class);
        $iteratorMock->shouldReceive('current')
            ->twice()
            ->andReturn($originalEntryMock);

        $newEntryMock = Mockery::mock(CommonSectionInterface::class);
        $this->readSection->shouldReceive('read')
            ->twice()
            ->with(
                Mockery::on(
                    function (ReadOptions $readOptions) {
                        $this->assertSame('sexy', (string)$readOptions->getSection()[0]);
                        if ($readOptions->getId()) {
                            $this->assertSame(9, $readOptions->getId()->toInt());
                        } elseif ($readOptions->getSlug()) {
                            $this->assertSame('snail', (string)$readOptions->getSlug());
                        }

                        return true;
                    }
                )
            )
            ->andReturn($iteratorMock);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiUpdateEntry::NAME, Mockery::type(ApiUpdateEntry::class)]);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([
                ApiBeforeEntryUpdatedAfterValidated::NAME,
                Mockery::type(ApiBeforeEntryUpdatedAfterValidated::class)]
            );

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiEntryUpdated::NAME, Mockery::type(ApiEntryUpdated::class)]);


        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();
        $mockedForm->shouldReceive('submit')->twice();
        $mockedForm->shouldReceive('getName')->twice();
        $mockedForm->shouldReceive('isValid')->andReturn(true);
        $mockedForm->shouldReceive('getData')
            ->andReturn($newEntryMock);

        $this->form->shouldReceive('buildFormForSection')
            ->twice()
            ->andReturn($mockedForm);

        $this->createSection->shouldReceive('save')
            ->with($newEntryMock)
            ->twice()
            ->andReturn(true);

        $response = $this->controller->updateEntryById('sexy', 9);
        $this->assertSame(
            '{"code":200,"success":true,"errors":false,"entry":[]}',
            $response->getContent()
        );

        $response = $this->controller->updateEntryBySlug('sexy', 'snail');
        $this->assertSame(
            '{"code":200,"success":true,"errors":false,"entry":[]}',
            $response->getContent()
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::updateEntryById
     * @covers ::updateEntryBySlug
     */
    public function it_does_not_update_entries_and_returns_correct_response()
    {
        $mockedForm = Mockery::mock(SymfonyFormInterface::class)->shouldDeferMissing();

        $mockedForm->shouldReceive('handleRequest')->never();
        $mockedForm->shouldReceive('isValid')->andReturn(false);
        $mockedForm->shouldReceive('getName')->andReturn('name of form');
        $mockedForm->shouldReceive('getIterator')->andReturn(new \ArrayIterator([$mockedForm]));
        $mockedForm->shouldReceive('submit')->with('foo', false);

        $originalEntryMock = Mockery::mock(CommonSectionInterface::class);
        $iteratorMock = Mockery::mock(\ArrayIterator::class);
        $iteratorMock->shouldReceive('current')
            ->twice()
            ->andReturn($originalEntryMock);

        $this->readSection->shouldReceive('read')
            ->twice()
            ->with(
                Mockery::on(
                    function (ReadOptions $readOptions) {
                        $this->assertSame('sexy', (string)$readOptions->getSection()[0]);
                        if ($readOptions->getId()) {
                            $this->assertSame(9, $readOptions->getId()->toInt());
                        } elseif ($readOptions->getSlug()) {
                            $this->assertSame('snail', (string)$readOptions->getSlug());
                        }

                        return true;
                    }
                )
            )
            ->andReturn($iteratorMock);

        $error = Mockery::mock(FormError::class)->makePartial();
        $error->shouldReceive('getMessage')->andReturn('you are wrong!');
        $mockedForm->shouldReceive('getErrors')
            ->andReturn([$error]);

        $this->form->shouldReceive('buildFormForSection')
            ->twice()
            ->andReturn($mockedForm);

        $mockedRequest = Mockery::mock(Request::class)->makePartial();
        $mockedRequest->request = Mockery::mock(ParameterBag::class)->makePartial();
        $mockedRequest->shouldReceive('get')
            ->with('form')
            ->andReturn(['no']);

        $mockedRequest->shouldReceive('get')
            ->with('abort')
            ->andReturn(null);

        $mockedRequest->shouldReceive('getMethod')
            ->andReturn('not options');
        $mockedRequest->shouldReceive('get')
            ->with('name of form')
            ->andReturn('foo');

        $mockedRequest->headers = Mockery::mock(HeaderBag::class);
        $mockedRequest->headers->shouldReceive('get')->with('Origin')
            ->andReturn('Some origin');

        $this->createSection->shouldReceive('save')
            ->never();

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->andReturn($mockedRequest);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiUpdateEntry::NAME, Mockery::type(ApiUpdateEntry::class)]);

        $response = $this->controller->updateEntryById('sexy', 9);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            '{"code":400,"errors":{"0":"you are wrong!","name of form":["you are wrong!"]}}',
            $response->getContent()
        );

        $response = $this->controller->updateEntryBySlug('sexy', 'snail');
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(
            '{"code":400,"errors":{"0":"you are wrong!","name of form":["you are wrong!"]}}',
            $response->getContent()
        );
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::deleteEntryById
     * @covers ::deleteEntryBySlug
     * @runInSeparateProcess
     */
    public function it_deletes_entries()
    {
        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->twice()
            ->andReturn($request);

        $this->readSection->shouldReceive('read')
            ->twice()
            ->andReturn(new \ArrayIterator([$entryMock]));

        $this->deleteSection->shouldReceive('delete')
            ->twice()
            ->with($entryMock)
            ->andReturn(true);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiEntryDeleted::NAME, Mockery::type(ApiEntryDeleted::class)]);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiDeleteEntry::NAME, Mockery::type(ApiDeleteEntry::class)]);

        $response = $this->controller->deleteEntryById('notsexy', 1);
        $this->assertSame('{"success":true}', $response->getContent());

        $response = $this->controller->deleteEntryBySlug('notsexy', 'snail');
        $this->assertSame('{"success":true}', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::deleteEntryById
     * @covers ::deleteEntryBySlug
     * @runInSeparateProcess
     */
    public function it_does_not_delete_entries_and_return_the_correct_response()
    {
        $entryMock = Mockery::mock(CommonSectionInterface::class);

        $request = new Request([], [], [], [], [], ['HTTP_ORIGIN' => 'iamtheorigin.com']);

        $this->requestStack->shouldReceive('getCurrentRequest')
            ->twice()
            ->andReturn($request);

        $this->readSection->shouldReceive('read')
            ->twice()
            ->andReturn(new \ArrayIterator([$entryMock]));

        $this->deleteSection->shouldReceive('delete')
            ->twice()
            ->with($entryMock)
            ->andReturn(false);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiEntryDeleted::NAME, Mockery::type(ApiEntryDeleted::class)]);

        $this->dispatcher->shouldReceive('dispatch')
            ->twice()
            ->withArgs([ApiDeleteEntry::NAME, Mockery::type(ApiDeleteEntry::class)]);

        $response = $this->controller->deleteEntryById('notsexy', 1);
        $this->assertSame('{"success":false}', $response->getContent());

        $response = $this->controller->deleteEntryBySlug('notsexy', 'snail');
        $this->assertSame('{"success":false}', $response->getContent());
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::updateEntryBySlug
     */
    public function it_should_abort_with_abort_flag_on_update()
    {
        $request = Mockery::mock(Request::class);
        $this->requestStack->shouldReceive('getCurrentRequest')->andReturn($request);

        $request->shouldReceive('getMethod')
            ->once()
            ->andReturn('POST');

        $this->dispatcher->shouldReceive('dispatch');

        $request->shouldReceive('get')
            ->with('abort')
            ->once()
            ->andReturn(409);

        $response = $this->controller->updateEntryBySlug('sectionHandle', 'slug');

        $this->assertSame($response->getStatusCode(), 409);
    }

    /**
     * @test
     * @covers ::__construct
     * @covers ::createEntry
     */
    public function it_should_abort_with_abort_flag_on_create()
    {
        $request = Mockery::mock(Request::class);
        $this->requestStack->shouldReceive('getCurrentRequest')->andReturn($request);

        $request->shouldReceive('getMethod')
            ->once()
            ->andReturn('POST');

        $this->dispatcher->shouldReceive('dispatch');

        $request->shouldReceive('get')
            ->with('abort')
            ->once()
            ->andReturn(409);

        $response = $this->controller->createEntry('sectionHandle');

        $this->assertSame($response->getStatusCode(), 409);
    }
}
