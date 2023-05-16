<?php

declare(strict_types = 1);

namespace Pagemachine\Formlog\Tests\Functional\Domain\Form\Finishers;

use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Mvc\Request as ExtbaseRequest;
use TYPO3\CMS\Extbase\Security\Cryptography\HashService;
use TYPO3\CMS\Form\Domain\Factory\ArrayFormFactory;
use TYPO3\CMS\Form\Domain\Model\FormDefinition;
use TYPO3\CMS\Form\Domain\Runtime\FormRuntime\FormSession;
use TYPO3\CMS\Form\Domain\Runtime\FormState;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Testcase for Pagemachine\Formlog\Domain\Form\Finishers\LoggerFinisher
 */
final class LoggerFinisherTest extends FunctionalTestCase
{
    /**
     * @var array
     */
    protected $coreExtensionsToLoad = [
        'form',
    ];

    /**
     * @var array
     */
    protected $testExtensionsToLoad = [
        'typo3conf/ext/formlog',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['BE']['lockRootPath'] = ORIGINAL_ROOT;

        Bootstrap::initializeLanguageObject();

        $configurationManager = GeneralUtility::makeInstance(ConfigurationManager::class);
        $contentObjectRenderer = new ContentObjectRenderer();
        $contentObjectRenderer->setUserObjectType(ContentObjectRenderer::OBJECTTYPE_USER_INT);
        $configurationManager->setContentObject($contentObjectRenderer);

        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', ['uid' => 123]);
        $this->setUpFrontendRootPage(123);

        $siteConfiguration = GeneralUtility::makeInstance(SiteConfiguration::class);
        $siteConfiguration->createNewBasicSite('123', 123, 'http://localhost/');

        $_SERVER['HTTP_HOST'] = 'localhost';
        $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByRootPageId(123);
        $siteLanguage = $site->getLanguageById(0);
        $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
        $frontendUser->initializeUserSessionManager();

        $GLOBALS['TSFE'] = GeneralUtility::makeInstance(
            TypoScriptFrontendController::class,
            GeneralUtility::makeInstance(Context::class),
            $site,
            $siteLanguage,
            new PageArguments(123, '0', []),
            $frontendUser,
        );
        $GLOBALS['TSFE']->determineId();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TSFE']);
    }

    /**
     * @test
     * @dataProvider formData
     */
    public function logsSubmittedFormData(array $fields, array $formValues, string $expectedData)
    {
        $formDefinition = $this->buildFormDefinition([
            'renderables' => [
                'page' => [
                    'renderables' => $fields,
                ],
            ],
            'finishers' => [
                [
                    'identifier' => 'LogFormData',
                ],
            ],
        ]);

        $this->submitForm($formDefinition, $formValues);

        $logEntry = $this->getConnectionPool()->getConnectionForTable('tx_formlog_entries')
            ->select(['*'], 'tx_formlog_entries')
            ->fetchAssociative();

        $this->assertSame(123, $logEntry['pid'] ?? null);
        $this->assertSame($formDefinition->getIdentifier(), $logEntry['identifier'] ?? null);
        $this->assertSame($expectedData, $logEntry['data'] ?? null);
        $this->assertSame('[]', $logEntry['finisher_variables'] ?? null);
    }

    public function formData(): \Generator
    {
        yield 'basic' => [
            [
                [
                    'identifier' => 'name',
                    'type' => 'Text',
                ],
            ],
            [
                'name' => 'Tester',
            ],
            '{"name":"Tester"}',
        ];

        yield 'date' => [
            [
                [
                    'identifier' => 'date',
                    'type' => 'Date',
                ],
            ],
            [
                'date' => '2022-02-07',
            ],
            '{"date":"07.02.2022"}',
        ];

        yield 'date with custom display format' => [
            [
                [
                    'identifier' => 'date',
                    'type' => 'Date',
                    'properties' => [
                        'displayFormat' => 'Y-m-d',
                    ],
                ],
            ],
            [
                'date' => '2022-02-07',
            ],
            '{"date":"2022-02-07"}',
        ];

        // TODO: Research why "DatePicker" fails completely

        $temporaryFilePath = tempnam(sys_get_temp_dir(), 'LoggerFinisherTest');
        file_put_contents($temporaryFilePath, 'Test file for upload');

        yield 'file upload' => [
            [
                [
                    'identifier' => 'upload',
                    'type' => 'FileUpload',
                    'properties' => [
                        'saveToFileMount' => '1:/',
                        'allowedMimeTypes' => [
                            'text/plain',
                        ],
                    ],
                ],
            ],
            [
                'upload' => [
                    'name' => 'test.txt',
                    'type' => 'text/plain',
                    'size' => filesize($temporaryFilePath),
                    'tmp_name' => $temporaryFilePath,
                    'error' => UPLOAD_ERR_OK,
                ],
            ],
            '{"upload":{"file":{"name":"test.txt"}}}',
        ];
    }

    /**
     * @test
     */
    public function logsFinisherVariables()
    {
        $formDefinition = $this->buildFormDefinition([
            'renderables' => [
                'page' => [
                    'renderables' => [
                        [
                            'identifier' => 'name',
                            'type' => 'Text',
                        ],
                    ],
                ],
            ],
            'finishers' => [
                [
                    'identifier' => 'SaveToDatabase',
                    'options' => [
                        'table' => 'pages',
                        'databaseColumnMappings' => [
                            'title' => [
                                'value' => 'Page created by form',
                            ],
                        ],
                    ],
                ],
                [
                    'identifier' => 'LogFormData',
                    'options' => [
                        'finisherVariables' => [
                            'SaveToDatabase' => [
                                'insertedUids.0',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->submitForm($formDefinition, [
            'name' => 'Tester',
        ]);

        $logEntry = $this->getConnectionPool()->getConnectionForTable('tx_formlog_entries')
            ->select(['*'], 'tx_formlog_entries')
            ->fetchAssociative();

        $this->assertSame(123, $logEntry['pid'] ?? null);
        $this->assertSame($formDefinition->getIdentifier(), $logEntry['identifier'] ?? null);
        $this->assertSame('{"name":"Tester"}', $logEntry['data'] ?? null);
        $this->assertSame('{"SaveToDatabase":{"insertedUids.0":124}}', $logEntry['finisher_variables'] ?? null);
    }

    protected function buildFormDefinition(array $configuration): FormDefinition
    {
        $commonConfiguration = [
            'prototypeName' => 'standard',
            'identifier' => 'LoggerFinisherTest',
            'renderables' => [
                'page' => [
                    'identifier' => 'page1',
                    'type' => 'Page',
                ],
            ],
        ];

        $arrayFormFactory = GeneralUtility::makeInstance(ArrayFormFactory::class);
        $formDefinition = $arrayFormFactory->build(array_merge_recursive($commonConfiguration, $configuration));

        return $formDefinition;
    }

    protected function submitForm(FormDefinition $formDefinition, array $formValues = [])
    {
        $formState = GeneralUtility::makeInstance(FormState::class);
        $formState->setLastDisplayedPageIndex(0);

        foreach ($formValues as $name => $value) {
            $formState->setFormValue($name, $value);
        }

        $requestArguments = array_merge(
            $formValues,
            [
                '__currentPage' => 1,
                '__state' => GeneralUtility::makeInstance(HashService::class)->appendHmac(base64_encode(serialize($formState))),
            ]
        );

        if (class_exists(FormSession::class)) {
            $requestArguments['__session'] = GeneralUtility::makeInstance(FormSession::class)->getAuthenticatedIdentifier();
        }

        $request = GeneralUtility::makeInstance(ExtbaseRequest::class)
            ->withMethod('POST')
            ->withArguments([
                $formDefinition->getIdentifier() => $requestArguments,
            ]);
        $formRuntime = $formDefinition->bind($request);

        $formRuntime->render();
    }
}
