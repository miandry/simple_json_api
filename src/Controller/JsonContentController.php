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

        $fields = \Drupal::request()->get('fields');
        $changes = \Drupal::request()->get('changes'); // change name field ouput
        $values = \Drupal::request()->get('values'); // change name field ouput
   
        $jsons =  \Drupal::service('simple_json_api.manager')->listQueryExecute($entitype,$bundle);
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
