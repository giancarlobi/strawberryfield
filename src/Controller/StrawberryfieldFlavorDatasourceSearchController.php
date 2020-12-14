<?php

namespace Drupal\strawberryfield\Controller;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\strawberryfield\Plugin\search_api\datasource\StrawberryfieldFlavorDatasource;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\strawberryfield\StrawberryfieldUtilityService;
use Drupal\search_api\ParseMode\ParseModePluginManager;


/**
 * A Wrapper Controller to access Twig processed JSON on a URL.
 */
class StrawberryfieldFlavorDatasourceSearchController extends ControllerBase {

  /**
   * Symfony\Component\HttpFoundation\RequestStack definition.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * The Strawberry Field Utility Service.
   *
   * @var \Drupal\strawberryfield\StrawberryfieldUtilityService
   */
  protected $strawberryfieldUtility;

  /**
   * The parse mode manager.
   *
   * @var \Drupal\search_api\ParseMode\ParseModePluginManager
   */
  protected $parseModeManager;

  /**
   * StrawberryfieldFlavorDatasourceSearchController constructor.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The Symfony Request Stack.
   * @param \Drupal\strawberryfield\StrawberryfieldUtilityService $strawberryfield_utility_service
   *   The SBF Utility Service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entitytype_manager
   *   The Entity Type Manager.
   * @param \Drupal\search_api\ParseMode\ParseModePluginManager $parse_mode_manager
   *   The Search API parse Manager
   */
  public function __construct(
    RequestStack $request_stack,
    StrawberryfieldUtilityService $strawberryfield_utility_service,
    EntityTypeManagerInterface $entitytype_manager,
    ParseModePluginManager $parse_mode_manager
  ) {
    $this->request = $request_stack;
    $this->strawberryfieldUtility = $strawberryfield_utility_service;
    $this->entityTypeManager = $entitytype_manager;
    $this->parseModeManager = $parse_mode_manager;

  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('request_stack'),
      $container->get('strawberryfield.utility'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.search_api.parse_mode')
    );
  }


  /**
   * @param \Drupal\Core\Entity\ContentEntityInterface $node
   * @param string $processor
   * @param string $fileuuid
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   */
  public function search(
    Request $request,
    ContentEntityInterface $node,
    string $fileuuid = 'all',
    string $processor = 'ocr'
  ) {





    if (!Uuid::isValid($fileuuid) && $fileuuid !== 'all') {
      // We do not want to expose the user to errors here?
      // So an empty JSON response is better?
      return new JsonResponse([]);
    }
    if ($input = $request->query->get('q')) {

      $indexes = StrawberryfieldFlavorDatasource::getValidIndexes();
      $snippets = $this->flavorfromSolrIndex(
        $input,
        $node->id(),
        $processor,
        $fileuuid,
        $indexes
      );

      $response = new CacheableJsonResponse(
        json_encode($snippets),
        200,
        ['content-type' => 'application/json'],
        TRUE
      );


      if ($response) {
        // Set CORS. IIIF and others will assume this is true.
        $response->headers->set('access-control-allow-origin', '*');
        $response->addCacheableDependency($node);
        if ($callback = $request->query->get('callback')) {
          $response->setCallback($callback);
        }

      }
      return $response;

    } else {
      return new JsonResponse([]);
    }
  }

  protected function flavorfromSolrIndex($term, $nodeid, $processor, $file_uuid, $indexes) {
    /* @var \Drupal\search_api\IndexInterface[] $indexes */

    $result_snippets = [];
    foreach ($indexes as $search_api_index) {

      // Create the query.
      // How many?
      $query = $search_api_index->query([
        'limit' => 100,
        'offset' => 0,
      ]);

      $parse_mode = $this->parseModeManager->createInstance('terms');
      $query->setParseMode($parse_mode);
      $query->sort('search_api_relevance', 'DESC');
      $query->keys($term);
      $query->setFulltextFields(['ocr_text']);
      $query->addCondition('parent_id', $nodeid)
        ->addCondition('search_api_datasource', 'strawberryfield_flavor_datasource')
        ->addCondition('processor_id', $processor);
      // IN the case of multiple files being used in the same IIIF manifest we do not limit
      // Which file we are loading.
      // @IDEA. Since the Rendered Twig templates (metadata display) that generate a IIIF manifest are cached
      // We could also pass arguments that would allow us to fetch that rendered JSON
      // And preprocess everything here.
      // Its like having the same Twig template on front and back?
      // @giancarlobi. Maybe an idea for the future once this "works".

      if ($file_uuid != 'all') {
        if (Uuid::isValid($file_uuid)) {
          $query->addCondition('file_uuid', $file_uuid);
        }
      }

      $allfields_translated_to_solr = $search_api_index->getServerInstance()->getBackend()->getSolrFieldNames($query->getIndex());
      if (isset($allfields_translated_to_solr['ocr_text'])) {
        // Will be used by \strawberryfield_search_api_solr_query_alter
        $query->setOption('ocr_highlight','on');
      }


      // If we allow Extra processing here Drupal adds Content Access Check
      // That does not match our Data Source \Drupal\search_api\Plugin\search_api\processor\ContentAccess
      // we get this filter (see 2nd)
      /*
       *   array (
        0 => 'ss_search_api_id:"strawberryfield_flavor_datasource/2006:1:en:3dccdb09-f79f-478e-81c5-0bb680c3984e:ocr"',
        1 => 'ss_search_api_datasource:"strawberryfield_flavor_datasource"',
        2 => '{!tag=content_access,content_access_enabled,content_access_grants}(ss_search_api_datasource:"entity:file" (+(bs_status:"true" bs_status_2:"true") +(sm_node_grants:"node_access_all:0" sm_node_grants:"node_access__all")))',
        3 => '+index_id:default_solr_index +hash:1evb7z',
        4 => 'ss_search_api_language:("en" "und" "zxx")',
      ),
       */


      $page_prefix = 'sequence_';
      $page_prefix_len = strlen($page_prefix);
      $query->setProcessingLevel(QueryInterface::PROCESSING_BASIC);
      $results = $query->execute();
      $extradata = $results->getAllExtraData();
      // Just in case something goes wrong with the returning region text
      $region_text = $term;
      if ($results->getResultCount() >= 1) {
        if (isset($extradata['search_api_solr_response']['ocrHighlighting']) && count(
            $extradata['search_api_solr_response']['ocrHighlighting']
          ) > 0) {
          foreach ($extradata['search_api_solr_response']['ocrHighlighting'] as $sol_doc_id => $field) {
            $result_snippets_base = [];
            if (isset($field[$allfields_translated_to_solr['ocr_text']]['snippets'])) {
              foreach ($field[$allfields_translated_to_solr['ocr_text']]['snippets'] as $snippet) {
                $result_snippets_base = [
                  'par' => [
                    [
                      'page' => substr(
                        $snippet['pages'][0]['id'],
                        $page_prefix_len
                      ),
                      'boxes' => [],
                    ]
                  ]
                ];

                foreach ($snippet['highlights'] as $highlight) {
                  $region_text = str_replace(
                    ['<em>', '</em>'],
                    ['{{{', '}}}'],
                    $snippet['regions'][$highlight[0]['parentRegionIdx']]["text"]
                  );
                  $result_snippets_base['par'][0]['boxes'][] = [
                    "l" => $highlight[0]['ulx'],
                    "t" => $highlight[0]['uly'],
                    "r" => $highlight[0]['lrx'],
                    "b" => $highlight[0]['lry'],
                    "page" => $result_snippets_base['par'][0]['page']
                  ];
                }
              }
            }
            $result_snippets_base["text"] = $region_text;
            $result_snippets[] = $result_snippets_base;
          }
        }
      }
    }
    return ['matches' => $result_snippets];
  }
}

