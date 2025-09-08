<?php
/**
 * Created by PhpStorm.
 * User: miandry
 * Date: 2020/5/6
 * Time: 12:15 PM
 */

namespace Drupal\simple_json_api;


use Drupal\entity_parser\EntityParser;
use Drupal\Component\Utility\Html;

class Custom extends EntityParser
{
    // term
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_entity_reference_taxonomy_term($entity, $field)
    {
        $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);

        $result = [];
        if ($bool) {
            $terms = $entity->get($field)->getValue();
            $language = \Drupal::languageManager()->getCurrentLanguage()->getId();

            foreach ($terms as $key => $value) {
                $term = $this->loader_object($value['target_id'], 'taxonomy_term');
                if ($term && $term->hasTranslation($language)) {
                    $term = $term->getTranslation($language);
                }
                if (is_object($term)) {
                    $result[] = [
                        "term" => $term,
                        "title" => $term->label(),
                        "tid" => $value['target_id'],
                    ];
                }
            }
            $is_multple = $entity->get($field)->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
            if (!$is_multple && count($result) == 1) {
                return array_shift($result);
            }
        }

        return $result;
    }
     /// paragraph
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_entity_reference_revisions($entity, $field)
    {

        $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);
        $item = [];
        if ($bool) {
            $fields = $entity->get($field)->getValue();
            if (!empty($fields)) {
                foreach ($fields as $key => $field_item) {
                    $paragraph = $this->loader_object($field_item['target_id'], 'paragraph');
                    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
                    if ($paragraph && method_exists($paragraph, 'hasTranslation') && $paragraph->hasTranslation($language)) {
                        $paragraph = $paragraph->getTranslation($language);
                    }
                    if (is_object($paragraph)) {
                        $item[] = $this->entity_parser_load($paragraph);
                    }
                }
                $is_multple = $entity->get($field)->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
                if (!$is_multple && count($item) == 1) {
                    return array_shift($item);
                }
            }

        }

        return $item;
    }
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_field_catalogue($entity, $field)
    {
      $term = ($entity->{$field}->entity);
      return ['name'=>$term->label(),'tid'=>$term->id()];
    }
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_body($entity, $field)
    {   $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);
        if ($bool) {
            $body = $entity->{$field}->value ;
            global $base_url ;
            $dom = Html::load($body) ;
            foreach ($dom->getElementsByTagName('img') as $img) {
                $src = $img->getAttribute('src');
                $img->setAttribute('src',$base_url.$src);
            }
            $body = $dom->saveHTML();
            $json = preg_replace('~<(?:!DOCTYPE|/?(?:html|head|body|meta))[^>]*>\s*~i', '', $body);
            return $json ;
        }

        return [];

    }
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_field_pr($entity, $field)
    {
        $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);

        $item = null;
        if ($bool) {
            $fields = $entity->get($field)->getValue();
            if (!empty($fields)) {
                foreach ($fields as $key => $field_item) {
                    $paragraph = $this->loader_object($field_item['target_id'], 'paragraph');
                    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
                    if ($paragraph && method_exists($paragraph, 'hasTranslation') && $paragraph->hasTranslation($language)) {
                        $paragraph = $paragraph->getTranslation($language);
                    }
                    if (is_object($paragraph)) {
                        $paragraph_array = $this->entity_parser_load($paragraph);
                        $image = $paragraph_array['medias'] ;
                        $text = $paragraph_array['field_text_long'] ;
                        $text_array = explode('/',$text);
                        $type = $paragraph_array['field_typ'];
                        $row = [];
                        foreach ($text_array as $key => $txt){
                            $row['data'][$key] = [
                                'label' => $txt ,
                                'image' => $image[$key]
                            ] ;
                        }
                        unset( $type ['term']);
                        $row['type'] =  $type ;
                    }
                    $item[] =$row ;
                }
            }

        }
        return $item;
    }
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_field_autre_prix($entity, $field)
    {
        $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);

        $item = null;
        if ($bool) {
            $fields = $entity->get($field)->getValue();
            if (!empty($fields)) {
                foreach ($fields as $key => $field_item) {
                    $paragraph = $this->loader_object($field_item['target_id'], 'paragraph');
                    $language = \Drupal::languageManager()->getCurrentLanguage()->getId();
                    if ($paragraph && method_exists($paragraph, 'hasTranslation') && $paragraph->hasTranslation($language)) {
                        $paragraph = $paragraph->getTranslation($language);
                    }
                    if (is_object($paragraph)) {
                        $paragraph_array = $this->entity_parser_load($paragraph);
                        $item = $paragraph_array['field_prix_vente'];
                        continue ;
                    }
                }
            }

        }
        return $item;
    }
    function ers_451e8e6d7b53f8a06e3f8517cf02b856_entity_reference_media($entity, $field)
    {
        $bool = \Drupal::service('drupal.helper')->helper->is_field_ready($entity, $field);
        $field_value = NULL;
        $result = [];
        if ($bool) {
            $medias = $entity->{$field}->referencedEntities();
            foreach ($medias as $key => $media) {
                $image = $this->ers_451e8e6d7b53f8a06e3f8517cf02b856_image_file($media, 'field_media_image');
                $result[] = $image['url'];
            }
            $is_multple = $entity->get($field)->getFieldDefinition()->getFieldStorageDefinition()->isMultiple();
            if (!$is_multple && count($result) == 1) {
                return array_shift($result);
            }
        }
        return $result;
    }

}