<?php

namespace Drupal\simple_json_api\Controller;

use Drupal\simple_json_api\ApiJsonParser;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\user\Entity\User;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Class CcmsContentController.
 *
 * @package Drupal\ccms_core\Controller
 */
class JsonContentController extends ControllerBase implements ContainerInjectionInterface
{

    /**
     * The date formatter service.
     *
     * @var \Drupal\Core\Datetime\DateFormatterInterface
     */
    protected $dateFormatter;

    /**
     * The renderer service.
     *
     * @var \Drupal\Core\Render\RendererInterface
     */
    protected $renderer;

    /**
     * Constructs a NodeController object.
     *
     * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
     *   The date formatter service.
     * @param \Drupal\Core\Render\RendererInterface $renderer
     *   The renderer service.
     */
    public function __construct(DateFormatterInterface $date_formatter, RendererInterface $renderer)
    {
        $this->dateFormatter = $date_formatter;
        $this->renderer = $renderer;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container)
    {
        return new static(
            $container->get('date.formatter'),
            $container->get('renderer')
        );
    }
    public function uploader(){
        $json = [
            'status' => false 
        ];
        $uri = 'public://';
        $stream_wrapper_manager = \Drupal::service('stream_wrapper_manager')->getViaUri($uri);
        $file_path = $stream_wrapper_manager->realpath();

        foreach($_FILES as $fileItem){

            $status =  move_uploaded_file($fileItem['tmp_name'],  $file_path."/".$fileItem['name']);
            if($status){
                $name = basename($fileItem['name']) ;
                $url = file_create_url($uri."/".$fileItem['name']);
                $fields=[
                    'name' =>  $name,
                    'field_media_image' => $url
                ];
                $image = \Drupal::service('crud')->save('media', 'image', $fields);
                if (is_object($image)) {
                    $json = [
                        'id' => $image->id(),
                        'status' => true 
                    ];
                } else {
                    $json = [
                        'status' => false 
                    ];
                }
                unlink($file_path."/".$fileItem['name']);
            }
        }
        if(isset($_GET['destination'])){
            $base_url = \Drupal::request()->getSchemeAndHttpHost();
            $url = $base_url.$_GET['destination'].'?fid=';
            header("Location:". $url );
            exit();
        }
        return new JsonResponse($json);
    }
    public function insertAPIEntity($entity_name,$bundle){
        $json = [];
        $parser_node_json = \Drupal::service('product_json');
        $method = \Drupal::request()->getMethod();
        $id = null;
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $content = json_decode($content, TRUE);
                
               // $json  =  is_array( $content);
                $id = $parser_node_json->insertAPIEntity($content,$entity_name,$bundle);
            }
        }
        $item = \Drupal::service('entity_parser.manager')->node_parser($id);
        $json = ($id)? ['item'=> $item,'status'=>'success']:['item'=> $id,'status'=>'error'] ;
        return new JsonResponse($json);
    }

    public function insertAPIProduit(){
        $parser_node_json = \Drupal::service('product_json');
        $method = \Drupal::request()->getMethod();
        $status = null;
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $produit = json_decode($content, TRUE);
                $status = $parser_node_json->insertAPIProduit($produit);
            }
        }
        $json = ($status)? ['item'=> $status,'status'=>'success']:['item'=> $status,'status'=>'error'] ;
        return new JsonResponse($json);
    }

    function apiMigration(){
        $parser_node_json = \Drupal::service('product_json');
        $method = \Drupal::request()->getMethod();
        $status = [];
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $prods = json_decode($content, TRUE);
                $status = $parser_node_json->migrateProduct($prods);
            }
        }
        return $status;
    }
    function apiEntitySingle($entity_type,$id){
        $fields = [];
        $field_param = \Drupal::request()->get('fields');
        $cache = \Drupal::request()->get('c') ;
        if($cache && $cache == 1){
            drupal_flush_all_caches();
        }       
        if($field_param){
          $fields = explode(',',$field_param);
        }
        $result = [];
        $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($id);
        if(is_object($entity)){
            $options['#hook_class'] = "\Drupal\simple_json_api\Custom";
            $result = \Drupal::service('entity_parser.manager')->parser($entity,$fields,$options);
        }
        return new JsonResponse($result);

    }
    function apiProductSingle($id){
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
        $result = [];
        if(is_object($node)){
            $result = \Drupal::service('entity_parser.manager')->parser($node);
        }
        return new JsonResponse($result);
    }
    function apiProducts()
    {
        $query = \Drupal::entityQuery('node')
            ->condition('type', 'produit');
        //    ->condition('status', '1');
        $start = \Drupal::request()->get('_start');
        $end = \Drupal::request()->get('_end');
        if ($start == null || $end == null) {
            $query->range(0,10);
        }else{
            $query->range($start, $end);
        }
        $query->sort('nid', 'DESC');
        $nids = $query->execute();
        $results = [];
        $i = 0;
        $product_json = \Drupal::service('product_json');
        foreach ($nids as $key => $id) {
            $node = \Drupal::entityTypeManager()->getStorage('node')->load($id);
            $results[] = $product_json->productItem($node) ;
        }
        return $this->responseCacheableJson($results);
    }
    function apiPayement(){
        $method = \Drupal::request()->getMethod();
        $results = [];
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $payement = json_decode($content, TRUE);
                $commandes =  \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(
                    ["type"=> 'commande',"title"=>$payement['orderId']]);
                if(!empty($commandes)){
                 //   var_dump(array_keys($commandes);
                    $fields['title'] = $payement['reference'];
                    $fields['commande_one'] = array_keys($commandes)[0] ;
                    $fields['field_montant'] = $payement['montant'] ;
                    $fields['field_payement_methode'] = $payement['method'] ;
                  //  $fields['field_ref'] = $payement['reference'] ;
                    $fields['field_text'] = $payement['phone'] ;
                    $fields['field_type_price'] = $payement['type_price'] ;
                    $pay = \Drupal::service('crud')->save('node', 'payement', $fields);
                    if (is_object($pay)) {
                        $status = true;
                        $payement['nid'] = $pay->id();
                        $results['data'] = $payement ;
                    } else {
                        $status = false;
                    }
                    $results['status'] = $status ;
                }
                // 2nd param to get as array
              //  $status = $parser_node_json->processOrder($orders);
            }
        }
        return new JsonResponse($results);
    }
    public function apiListJson()
    {

        $bundle = \Drupal::request()->get('bundle');
        $entitype = \Drupal::request()->get('entitype');
        $pager = \Drupal::request()->get('pager');
        $offset = \Drupal::request()->get('offset');
        $view = \Drupal::request()->get('view');
        $cat = \Drupal::request()->get('cat');
        $fields = \Drupal::request()->get('fields');
        if ($cat) {
            $entity = \Drupal::service('drupal.helper')->helper->getEntityByAlias($cat);
            if (is_object($entity)) {
                $id = $entity->id();
                $children = \Drupal::service('drupal.helper')->helper->taxonomy_get_children($id);
            } else {
                $children = [-1];
            }
        }
        if ($bundle && $entitype) {
            if ($offset == null) {
                $offset = 10;
            }
            $key_bundle = \Drupal::entityTypeManager()->getDefinition($entitype)->getKey('bundle');
            $query = \Drupal::entityQuery($entitype)->condition($key_bundle, $bundle);

            if ($entitype == 'node') {
                $query->sort('promote', 'DESC');
                $query->sort('nid', 'DESC');
                $query->condition('status', '1');
            }
            if ($cat && $children) {
                $query->condition('field_catalogue', $children, 'IN');
            }
            if ($pager) {
                $query->range($offset * ($pager - 1), $offset);
            } else {
                $query->range(0, $offset);
            }
            $json = $query->execute();
        } else {
            $json = ['empty parameter /api/v1/list?bundle=article&entitype=node'];
        }


        if ($view == 'full') {
            $results = [];
            foreach ($json as $key => $id) {
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype);
            }
            return $this->responseCacheableJson($results);
        }
        if(is_array($fields)){
            $results = [];
            foreach ($json as $key => $id) {
                $fields_array = $fields ;
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype, $fields_array, $options);
            }
            return $this->responseCacheableJson($results);
        }
        if(is_string($fields)){
            $results = [];
            foreach ($json as $key => $id) {
                $fields_array[] = $fields;
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype,  $fields_array, $options);
            }
            return $this->responseCacheableJson($results);
        }
        if(is_string($fields)){
            $results = [];
            foreach ($json as $key => $id) {
                $fields_array[] = $fields;
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype,  $fields_array, $options);
            }
            return $this->responseCacheableJson($results);
        }
        return $this->responseCacheableJson(array_values($json));
    }
    public function apiTerm($vid){
        $parser_node_json = new ApiJsonParser();
        $data =  \Drupal::request()->query->all();
        $results = $parser_node_json->taxonomy_load_multi_by_vid($vid,$data);
        return new JsonResponse($results);
    }

    public function apiOrder()
    {

        $method = \Drupal::request()->getMethod();
        $status = [];
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                // 2nd param to get as array
                $orders = json_decode($content, TRUE);
                foreach ($orders['data'] as $data) {
                    $str = '<table border="1">';
                    $str = $str . '<tr>';
                    $str = $str . '<th> Type </th>';
                    $str = $str . '<th> Details </th>';
                    $str = $str . '<th> Image </th>';
                    $str = $str . '</tr>';
                    foreach ($data['attrs'] as $attr) {
                        $str = $str . '<tr>';
                        $str = $str . '<td>' . $attr['type'] . '</td>';
                        $str = $str . '<td>' . $attr['label'] . '</td>';
                        if ($attr['image']) {
                            $str = $str . '<td> <img src="' . $attr['image'] . '" width="150" /> </td>';
                        }
                        $str = $str . '</tr>';
                    }
                    $str = $str . '</table>';
                    $fields['title'] = $orders['user']['nom'];
                    $fields['field_produit'] = $data['nid'];
                    $fields['field_text'] = $orders['user']['phone'];
                    $fields['body'] = $str;
                    $cart = \Drupal::service('crud')->save('node', 'demande', $fields);
                    if (is_object($cart)) {
                        $status[] = true;
                    } else {
                        $status[] = false;
                    }

                }
            }
        }
        if (!empty($status) && !in_array(false, $status)) {
            $results = ['status' => 1];
        } else {
            $results = ['status' => 0];
        }
        return new JsonResponse($results);
    }

    public function apiCategory()
    {
        $service = \Drupal::service('drupal.helper');
        $parents = $service->helper->taxonomy_first_level_by_vid('catalogue');
        $categories = [];

        foreach ($parents as $parent) {

            $term = \Drupal::service('entity_parser.manager')->parser($parent['object']);
            $category = $parent['tid'];
            $child = $service->helper->taxonomy_get_children($parent['tid']);
            foreach ($child as $item) {
                $category = $category . "," . $item;
            }
            $categories[] = [
                "value" => $category,
                "label" => $parent['name'],
                "image" => ($term['image'])?$term['image']['image']['url']:''
            ];

        }
        return $this->responseCacheableJson($categories);
    }
    protected function responseCacheableJson($data) {
        // Add Cache settings for Max-age and URL context.
        // You can use any of Drupal's contexts, tags, and time.

        $config = $this->config('system.performance');
        $build = [
            '#cache' => [
                // The max-age use system settings.
                'max-age' => $config->get('cache.page.max_age'),
                'contexts' => [
                    'url',
                ],
            ]
        ];

        $response = new CacheableJsonResponse($data);
        $response->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));
        return $response;
    }
    public function apiOrderV1()
    {
        //  $content = '{"user":{"name":"12222288xxx11","telephone":"qw","mail":"21","adress":{"city":"12","location":"12","provice":""}},"orders":[{"cart":{"quantity":1,"price":"900000","attributeList":{"Couleur":{"value":"vert","image":"http://47.91.115.233/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/f6_1.jpg?itok=HFgdLdjf"},"Pointure":{"value":"34"}},"product":{"title":"Chaussures à talons hauts en cuir verni","id":"19469","body":"<p>ok</p>","description":"","price":"900000","priceList":[{"name":"Details","id":"524","price":"900000","image":[]}],"images":["http://47.91.115.233/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/f6_1.jpg?itok=HFgdLdjf"],"attributes":[{"type":"Couleur","id":"329","values":[{"value":"vert","image":"http://47.91.115.233/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/f6_1.jpg?itok=HFgdLdjf"}]},{"type":"Pointure","id":"288","values":[{"value":"33"},{"value":"34"},{"value":"35"},{"value":"36"},{"value":"37"},{"value":"38"},{"value":"39"},{"value":"40"}]},{"type":"Talon","id":"285","values":[{"value":"9cm"},{"value":"10.5cm"},{"value":"12cm"}]}],"category":{"value":"Chaussures Femmes","id":"6"}},"uuid":"636xdkwjl6ekbp86sft"},"uuid":"792u75cghnpkc0e23bi"},{"cart":{"quantity":1,"price":"635000","attributeList":{},"product":{"number":0,"title":"Veste en cuir femme  avec ceinture amovible","id":"21298","body":"<p>ok</p>","description":"","price":"635000","priceList":[{"name":"Details","id":"524","price":"635000","image":[]}],"images":["http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm1.jpg?itok=S5dVnjko"],"attributes":[{"type":"Couleur","id":"329","values":[{"value":"noir","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm2.jpg?itok=ve5mGHUI"},{"value":"bleu","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm3.jpg?itok=oIjJASmB"},{"value":"rose","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm4.jpg?itok=IlaamD_s"},{"value":"vert","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm5.jpg?itok=P90aUiHs"}]},{"type":"Taille","id":"287","values":[{"value":"S"},{"value":"M"},{"value":"L"},{"value":"XL"}]}],"category":{"value":"Vêtements Femmes","id":"34"}},"uuid":"lduoqsy40wkbtdl4a4"},"uuid":"6i8q5gkm4z8kc0e23u8"},{"cart":{"quantity":1,"price":"635000","attributeList":{},"product":{"number":0,"title":"Veste en cuir femme  avec ceinture amovible","id":"28853","body":"<p>body</p>","description":"","price":"635000","priceList":[{"name":"Details","id":"524","price":"635000","image":[]}],"images":["http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm1.jpg?itok=S5dVnjko"],"attributes":[{"type":"Couleur","id":"329","values":[{"value":"noir","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm2.jpg?itok=ve5mGHUI"},{"value":"bleu","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm3.jpg?itok=oIjJASmB"},{"value":"rose","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm4.jpg?itok=IlaamD_s"},{"value":"vert","image":"http://www.e-roso.com/sites/eroso/files/styles/220x240/public/media/14460/image/2020-06/mm5.jpg?itok=P90aUiHs"}]},{"type":"Taille","id":"287","values":[{"value":"S"},{"value":"M"},{"value":"L"},{"value":"XL"}]}],"category":{"value":"Vêtements Femmes","id":"34"}},"uuid":"2kw5p6844ankbtdl7tl"},"uuid":"jummwms05mckc0e24pd"}]}';
        $parser_node_json = \Drupal::service('order_json');
        $method = \Drupal::request()->getMethod();
        $status = [];
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();

            if (!empty($content)) {
                $orders = json_decode($content, TRUE);
                // 2nd param to get as array
                $status = $parser_node_json->processOrder($orders);
            }
        }
        if ($status) {
            $results = ['status' => 1];
        } else {
            $results = ['status' => 0];
        }
        return new JsonResponse($results);
    }

    /*** /api/v1/json?url=node/1231  **/
    public function apiJson($entity_type, $id)
    {

        $url = Xss::filter(\Drupal::request()->get('url'));
        $options['#hook_class'] = "\Drupal\simple_json_api\Custom";
        $fields = ["nid", "field_catalogue",
            "title", "medias", "body", "field_sku",
            "field_autre_prix", "field_pr"];
        $json = \Drupal::service('entity_parser.manager')
            ->loader_entity_by_type($id, $entity_type, $fields, $options);


        return $this->responseCacheableJson($json);
    }

    public function userEdit()
    {
        $parser_node_json = new ApiJsonParser();
        $method = \Drupal::request()->getMethod();

        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                $json = ($data);
                $json['status'] = false;
                if ($data['name'] && $data['phone']) {
                    $status = $parser_node_json->isUserNameExist($data['name']);
                    if ($status) {
                        $field_adress = [
                            'field_adress' => $data['adress']['province'] . " - " . $data['adress']['city'] . " - " . $data['adress']['location'],
                            'field_email' => $data['mail'],
                            'field_phone' => $data['phone']
                        ];

                        $adress = \Drupal::service('crud')->save('paragraph', 'adress', $field_adress);
                        $user = user_load_by_name($data['name']);
                       // var_dump($user->id());
                        $user->setEmail($data['mail']);
                      //  $user->set("field_phone", $data['phone']);
                        $user->set("field_adresse", $adress);
                       /// $user->setUsername($data['name']); //This username must be unique and accept only a-Z,0-9, - _ @ .
                        //   $user->addRole('authenticated'); //E.g: authenticated
                        $json['status'] = $user->save();
                        $json['id'] = $user->id();

                    }
                }
            }
        }
        return new JsonResponse($json);
    }

    public function register()
    {
        $parser_node_json = new ApiJsonParser();
        $method = \Drupal::request()->getMethod();
        $json['status'] = false;

        if ($method == "POST") {
            $content = \Drupal::request()->getContent();

            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                if ($data['name'] && $data['pass']) {

                    $status = $parser_node_json->isUserNameExist($data['name']);

                    if ($status) {
                        $json['name'] = $data['name'];
                        $json['error'] = 'Username exist deja';
                        $json['status'] = false;
                    } else {
                        $json['name'] = $data['name'];
                        $user = User::create();
                        $user->setPassword($data['pass']);
                        $user->enforceIsNew();
                        $user->setEmail("email@yahoo.fr");
                        $user->set('status',1);
                        $user->setUsername($data['name']); //This username must be unique and accept only a-Z,0-9, - _ @ .
                        if(isset($data['role'])){
                            $user->addRole($data['role']); //E.g: authenticated
                        }
             
                        $json['status'] = $user->save();
                        $json['token'] = $parser_node_json->generateToken($user);
                        $json['id'] = $user->id();
//                        $json['mail'] = ($data['mail'])? $data['mail'] : "" ;
//                        $json['adress']  = [
//                                'city' => ($data['city'])? $data['city']: "",
//                                'location' => ($data['location'])? $data['location']: "",
//                                'province' => ($data['province'])? $data['province']: ""
//                        ];
//                        $json['phone'] = ($data['phone'])? $data['phone'] : "" ;
                    }
                }
            }
        }
        return new JsonResponse($json);
    }

    public function login()
    {

        $method = \Drupal::request()->getMethod();
        $json['status'] = false;
        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
            if (!empty($content)) {
                $data = json_decode($content, TRUE);
                $json['name'] = $data['name'];
                $user = user_load_by_name($data['name']);
                if (is_object($user)) {
                    $user_array = \Drupal::service('entity_parser.manager')->user_parser($user);
                    $hashed_password = $user->getPassword();
                    $password_hasher = \Drupal::service('password');
                    $password = $data['pass'];
                    $json['mail'] = $user_array['mail'];
                    $json['token'] = \Drupal\Component\Utility\Crypt::hashBase64($hashed_password);
                    $json['status'] = ($password_hasher->check($password, $hashed_password));
    
                    
                    if($json['status']){
                        if($user_array['field_adresse']){
                            $adress = array_values($user_array['field_adresse'])[0];
                            $adress_array = explode('-',$adress['field_adress']);
                            $json['adress']  = [
                                'city' => ($adress_array)?$adress_array[1] : "",
                                'location' => ($adress_array)?$adress_array[2] : "",
                                'province' => ($adress_array)?$adress_array[0] : ""
                            ];
                            $json['phone'] = ($adress['field_phone'])? $adress['field_phone'] : "" ;
                        }
                    $json['id'] = $user->id();
                    $json['data'] = $user_array  ;
                    }else{
                        $json = [];
                        $json['mail'] = $user_array['mail'];
                        $json['name'] = $data['name'];
                        $json['status'] = false ;
                        $json['error'] = "Failed Authentification" ;
                    }
                }
            }
        }

        return new JsonResponse($json);
    }
    public function sendResetEmail() {
        $method = \Drupal::request()->getMethod();
        $json['status'] = false;

        if ($method == "POST") {
            $content = \Drupal::request()->getContent();
        $data = json_decode($content, TRUE);    // Check if email is provided.
        if (empty($data['email'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Email is required.'], 400);
        }
    
        $email = $data['email'];
    
        // Load the user by email.
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
        $user = reset($users);
    
        // If user does not exist.
        if (!$user) {
          return new JsonResponse(['status' => 'error', 'message' => 'User with this email does not exist.']);
        }
    
        // Generate a one-time login URL.
        $login_url = user_pass_reset_url($user);
    
        // Send the password reset email.
        

        $email = $user->getEmail();
        $subject = "Vous avez demandé une réinitialisation de mot de passe. Utilisez le lien ci-dessous pour réinitialiser votre mot de passe.<br/><a href='".$login_url."'>".$login_url."</a>";
        $body  = 'Password Reset Request';
        // Send the email.
        $service = \Drupal::service("mz_email.default");  
        $status = $service->sendMail($email, $body ,$subject );
        if ( $status === true) {
          return new JsonResponse([
            'status' => 'success',
            'message' => 'Password reset email sent successfully.'
          ]);
        }else{
            return new JsonResponse([
                'status' => 'error',
                'message' => 'Failed to send password reset email.'
              ]);

        }
      }
      return new JsonResponse([
        'status' => 'error',
        'message' => 'Failed to send password reset email.'
      ], 500);
    }
    
    public function apiMenu()
    {
        $menu = \Drupal::service('simplify_menu.menu_items')->getMenuTree();
        return $this->responseCacheableJson($menu['menu_tree']);
    }

    public function apiMenuChildren($name)
    {

        // $menu = \Drupal::service('simplify_menu.menu_items')->getMenuTree();
        //  return new JsonResponse($menu['menu_tree']);


    }

    public function apiRender()
    {
        $url = Xss::filter(\Drupal::request()->get('url'));
        $url_array = explode("/", $url);
        if (is_array($url_array) && sizeof($url_array) == 2) {
            $id = end($url_array);
            $entity_type = reset($url_array);
            $entity_type_manager = \Drupal::entityTypeManager();
            $entity = $entity_type_manager->getStorage($entity_type)->load($id);
            $view_builder = $entity_type_manager->getViewBuilder($entity_type);
            $result = $view_builder->view($entity, 'default');
            $json['content'] = \Drupal::service('renderer')->renderRoot($result);
            $json['id'] = $id;
        }
        return $this->responseCacheableJson($json);
    }


    /**
     * Custom access funciton
     *
     * @return Drupal\Core\Access\AccessResult
     */
    public function apiJsonAccess()
    {
        return AccessResult::allowed();
    }

    public function apiListJsonV2($entitype, $bundle)
    {

        $jsons =  \Drupal::service('mz_mobile_page.api')->listQueryExecute($entitype,$bundle);
        $results = [];
        $options = [];
        $json = $jsons["rows"];
        foreach ($json as $key => $id) {
            if(is_array($fields)){
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype, $fields, $options);
            }else{
                $results[] = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype);
            }
        }
        if($values){
            foreach ($results as $key => $item) {
                foreach ($item as $key_field => $value_field) {
                    if(isset($values[$key_field])){
                        $key_name = ($values[$key_field]);
                        $val = $this->getValueArray($item,$key_name );
                        $results[$key][$key_field] = $val ;
                    }
                }              
            }
        }
        if($changes){
            foreach ($results as $key => $item) {
                foreach ($item as $key_field => $value_field) {
                    if(isset($changes[$key_field])){
                        $results[$key][$changes[$key_field]] = $value_field ;
                        unset($results[$key][$key_field]);
                    }
                }
               
            }
        }

        $ouput = ["rows" => $results , "total" =>  $jsons["total"]];
        return new JsonResponse( $ouput);
       // return $this->responseCacheableJson($results);     
    }


    public function apiDetailsJsonV2($entitype, $bundle, $id){
        $fields = \Drupal::request()->get('fields');
        $changes = \Drupal::request()->get('changes'); // change name field ouput
        $values = \Drupal::request()->get('values'); // change name field ouput
        $options = [];
         if(is_array($fields)){
            $item = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype, $fields, $options);
        }else{
            $item = \Drupal::service('entity_parser.manager')->loader_entity_by_type($id, $entitype);
        }
                if($values){            
                        foreach ($item as $key_field => $value_field) {
                            if(isset($values[$key_field])){
                                $key_name = ($values[$key_field]);
                                $val = $this->getValueArray($item,$key_name );
                                $item[$key_field] = $val ;
                            }
                        }
                }
                if($changes){
                        foreach ($item as $key_field => $value_field) {
                            if(isset($changes[$key_field])){
                                $item[$changes[$key_field]] = $value_field ;
                                unset($item[$key_field]);
                            }
                        }                
                }
        return new JsonResponse($item);
    }


}
