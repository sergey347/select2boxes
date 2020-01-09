<?php

namespace Drupal\select2_bef\Plugin\views\exposed_form;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\better_exposed_filters\Plugin\views\exposed_form\BetterExposedFilters;
use Drupal\select2boxes\PreloadBuildTrait;

/**
 * Class Select2BEF.
 *
 * @ViewsExposedForm(
 *   id = "select2_bef",
 *   title = @Translation("Select2 BEF"),
 *   help = @Translation("Overrides BEF with select2boxes options.")
 * )
 *
 * @package Drupal\select2_bef\Plugin\views\exposed_form
 */
class Select2BEF extends BetterExposedFilters implements ContainerFactoryPluginInterface {

  /**
   * Trait builds preloaded entries list.
   */
  use PreloadBuildTrait;

  /**
   * Define available entity reference formats.
   */
  const ENTITY_REF_FORMATS = [
    'select2boxes_autocomplete_single',
    'select2boxes_autocomplete_multi',
  ];

  /**
   * The route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(RouteMatchInterface $route_match, EntityFieldManagerInterface $entity_field_manager, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, ...$defaults) {
    parent::__construct(...$defaults);

    $this->routeMatch = $route_match;
    $this->entityFieldManager = $entity_field_manager;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('current_route_match'),
      $container->get('entity_field.manager'),
      $container->get('module_handler'),
      $container->get('entity_type.manager'),
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.better_exposed_filters_filter_widget'),
      $container->get('plugin.manager.better_exposed_filters_pager_widget'),
      $container->get('plugin.manager.better_exposed_filters_sort_widget')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $select2_bef_options = [];

    foreach ($this->view->display_handler->getHandlers('filter') as $filter_id => $filter) {
      if (!$filter->isExposed()) {
        continue;
      }

      $select2_bef_options['filter'][$filter_id]['plugin_id'] = 'default';

      $bundles = $this->buildReferenceBundlesList(static::convertDatabaseFieldToFieldname($filter_id));
      if (!empty($bundles)) {
        reset($bundles);
        $first_key = key($bundles);
        $select2_bef_options['filter'][$filter_id]['configuration']['reference_bundles'] = $first_key;
      }

      $select2_bef_options['filter'][$filter_id]['configuration']['advanced']['enable_preload'] = FALSE;
      $select2_bef_options['filter'][$filter_id]['configuration']['advanced']['preload_count'] = 5;
      $select2_bef_options['filter'][$filter_id]['configuration']['advanced']['limited_search'] = FALSE;
      $select2_bef_options['filter'][$filter_id]['configuration']['advanced']['minimum_search_length'] = '';
    }

    $options_new = $this->createOptionDefaults(['bef' => $select2_bef_options]);
    $options_clone = $options;

    $bef = array_merge(
      $options_clone['bef']['contains']['filter'],
      $options_new['bef']['contains']['filter']
    );

    $options_clone['bef']['contains']['filter'] = $bef;

    return $options_clone;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $bef      = &$form['bef'];
    $settings = $this->options['bef'];

    foreach ($bef['filter'] as $name => $value) {

      if (static::isField($name)) {
        if (static::isEntityReferenceField($name)) {

          $bef['filter'][$name]['configuration']['advanced']['reference_bundles'] = [
            '#type'          => 'checkboxes',
            '#title'         => $this->t('Entity bundles'),
            '#default_value' => [$settings['filter'][$name]['configuration']['reference_bundles']],
            '#options'       => $this->buildReferenceBundlesList(static::convertDatabaseFieldToFieldname($name)),
            '#weight'        => -10,
            '#states'        => [
              'visible' => [
                [
                  ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                    'value' => 'select2boxes_autocomplete_multi',
                  ],
                ],
                'or',
                [
                  ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                    'value' => 'select2boxes_autocomplete_single',
                  ],
                ],
              ],
            ],
          ];

          // Add mandatory sign to the title.
          // We don't want do this via "#required" property,
          // because we need custom validation for this field
          // to prevent "required" errors being produced in the fields
          // which aren't use this at all.
          $bef['filter'][$name]['configuration']['advanced']['reference_bundles']['#title'] .= '<span class="form-required"></span>';

          $bef['filter'][$name]['configuration']['advanced']['enable_preload'] = [
            '#type'          => 'checkbox',
            '#title'         => $this->t('Enable preloaded entries'),
            '#default_value' => $settings['filter'][$name]['configuration']['advanced']['enable_preload'],
            '#weight'        => -10,
            '#states'        => [
              'visible' => [
                [
                  ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                    'value' => 'select2boxes_autocomplete_multi',
                  ],
                ],
              ],
            ],
          ];
          $bef['filter'][$name]['configuration']['advanced']['preload_count'] = [
            '#type'          => 'textfield',
            '#title'         => $this->t('Maximum number of entries that will be pre-loaded'),
            '#description'   => $this->t('If maximum number is not specified then all entries will be preloaded'),
            '#default_value' => $settings['filter'][$name]['configuration']['advanced']['preload_count'],
            '#weight'        => -10,
            '#states'        => [
              'visible' => [
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                  'value' => 'select2boxes_autocomplete_multi',
                ],
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][advanced][enable_preload]\"]" => [
                  'checked' => TRUE,
                ],
              ],
            ],
          ];
        }

        $bef['filter'][$name]['configuration']['advanced']['limited_search'] = [
          '#type'          => 'checkbox',
          '#title'         => $this->t('Limit search box visibility by list length'),
          '#default_value' => $settings['filter'][$name]['configuration']['advanced']['limited_search'],
          '#weight'        => -10,
          '#states'        => [
            'visible' => [
              [
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                  'value' => 'select2boxes_autocomplete_list',
                ],
              ],
              'or',
              [
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                  'value' => 'select2boxes_autocomplete_single',
                ],
              ],
            ],
          ],
        ];

        $bef['filter'][$name]['configuration']['advanced']['minimum_search_length'] = [
          '#type'          => 'textfield',
          '#title'         => $this->t('Minimum list length'),
          '#default_value' => $settings['filter'][$name]['configuration']['advanced']['minimum_search_length'],
          '#weight'        => -10,
          '#states'        => [
            'visible' => [
              [
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                  'value' => 'select2boxes_autocomplete_list',
                ],
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][advanced][limited_search]\"]" => [
                  'checked' => TRUE,
                ],
              ],
              'or',
              [
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][plugin_id]\"]" => [
                  'value' => 'select2boxes_autocomplete_single',
                ],
                ":input[name=\"exposed_form_options[bef][filter][$name][configuration][advanced][limited_search]\"]" => [
                  'checked' => TRUE,
                ],
              ],
            ],
          ],
        ];
      }

      if (self::isField($name) || $name == 'langcode') {
        $this->addIncludeIconsOption($bef, $name, $settings);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateOptionsForm(&$form, FormStateInterface $form_state) {
    $values = $form_state->getValue(['exposed_form_options', 'bef', 'filter']);

    foreach ($values as $name => &$value) {
      if (static::isEntityReferenceField($name)) {
        $reference = &$value['configuration']['advanced']['reference_bundles'];

        if (!empty($reference)) {
          // Remove zero-value options from the list.
          foreach ($reference as $bundle => $val) {
            if (empty($val)) {
              unset($reference[$bundle]);
            }
          }
        }

        // Custom "required" field validation (for entity reference fields only).
        $plugin_match = in_array($value['configuration']['plugin_id'], static::ENTITY_REF_FORMATS);

        if (empty($reference) && $plugin_match) {
          $element = &$form['bef']['filter'][$name]['configuration']['advanced']['reference_bundles'];
          $form_state->setError($element, $this->t('Entity bundles field is required'));
        }
      }
    }

    $form_state->setValue(['exposed_form_options', 'bef', 'filter'], $values);
    parent::validateOptionsForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    // Reset filter's extra option to Dropdown.

    /** @var \Drupal\views\ViewEntityInterface $storage */
    $storage = $this->routeMatch->getParameters()->get('view')->get('storage');
    $displays = $storage->get('display');

    $display_id = $this->routeMatch->getParameter('display_id');
    $filters = &$displays[$display_id]['display_options']['filters'];

    $bef = $form_state->getValue(['exposed_form_options', 'bef', 'filter']);

    foreach ($bef as $name => $value) {
      $plugin_match = in_array(
        $value['configuration']['plugin_id'],
        static::ENTITY_REF_FORMATS
      );

      if (static::isEntityReferenceField($name) && $plugin_match) {
        $filters[$name]['type'] = 'select';
      }
    }

    $storage->set('display', $displays);
    parent::submitOptionsForm($form, $form_state);
  }

  /**
   * Add "Include flags icons" option if all dependencies are met.
   *
   * @param array &$bef
   *   BEF options form.
   * @param string $name
   *   Element's name.
   * @param array $settings
   *   BEF settings array.
   */
  protected function addIncludeIconsOption(array &$bef, $name, array $settings) {
    // Check if the field is of allowed type to include flags icons.
    $types = ['language_field', 'language', 'country', 'langcode'];
    $match_type = in_array($this->getFieldType($name), $types);

    if ($match_type && $this->moduleHandler->moduleExists('flags')) {
      // Add a new option to the settings form.
      $bef['filter'][$name]['configuration']['advanced']['include_flags'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Include flags icons'),
        '#default_value' => $settings[$name]['configuration']['advanced']['include_flags'],
        '#weight'        => -10,
        '#states'        => [
          // Make it invisible for any widgets except Select2 Boxes.
          'visible' => [
            ":input[name=\"exposed_form_options[filter][bef][$name][configuration][plugin_id]\"]" => [
              'value' => 'select2boxes_autocomplete_list',
            ],
          ],
        ],
      ];
    }
  }

  /**
   * Check if the name is a field name.
   *
   * @param string $name
   *   Input name to check.
   *
   * @return bool
   *   Checking result.
   */
  protected static function isField($name) {
    return (bool) (stripos($name, 'field_') !== FALSE);
  }

  /**
   * Check if the field is entity reference.
   *
   * @param string $name
   *   Input field name to check.
   *
   * @return bool
   *   Checking result.
   */
  protected static function isEntityReferenceField($name) {
    return (bool) (stripos($name, '_target_id') !== FALSE);
  }

  /**
   * Get field's type.
   *
   * @param string $name
   *   Field's BEF name.
   *
   * @return string
   *   Field's type.
   */
  protected function getFieldType($name) {
    if (stripos($name, '_target_id') !== FALSE) {
      $name = static::convertDatabaseFieldToFieldname($name);
    }
    elseif (stripos($name, '_value') !== FALSE) {
      $name = str_replace('_value', '', $name);
    }

    $entity_type = $this->view->getBaseEntityType()->id();

    $field_definition = $this->entityFieldManager
      ->getFieldStorageDefinitions($entity_type)[$name];

    return $field_definition->getType();
  }

  /**
   * Build reference bundles list.
   *
   * @param string $field
   *   Field name.
   *
   * @return array
   *   Reference bundles list.
   */
  protected function buildReferenceBundlesList($field) {
    $bundles = [];
    $field_definition = $this->entityFieldManager
      ->getFieldStorageDefinitions($this->view->getBaseEntityType()->id())[$field];

    $entity_type = $field_definition->getSetting('target_type');

    $bundles_info = \Drupal::service('entity_type.bundle.info')
      ->getBundleInfo($entity_type);

    foreach ($bundles_info as $bundle_name => $bundle_label) {
      $bundles[$bundle_name] = $bundle_label['label'];
    }

    return $bundles;
  }

  /**
   * Convert db field name(column name) to field name.
   *
   * @param string $name
   *   Database field name.
   *
   * @return string
   *   Field name.
   */
  protected static function convertDatabaseFieldToFieldname($name) {
    return str_replace('_target_id', '', $name);
  }

  /**
   * {@inheritdoc}
   */
  public function renderExposedForm($block = FALSE) {
    $form = parent::renderExposedForm($block);
    $settings = $this->options['bef']['filter'];

    $map = $this->entityFieldManager
      ->getFieldMapByFieldType('entity_reference');

    // Additional code to allow "preloading" option works with exposed filters.
    foreach ($settings as $name => $setting) {
      if (static::isField($name)) {
        if ($setting['configuration']['advanced']['enable_preload']) {
          $field_name = static::convertDatabaseFieldToFieldname($name);
          $count      = $setting['configuration']['advanced']['preload_count'];
          $field      = $map[$this->view->getBaseEntityType()->id()][$field_name];
          $bundle     = reset($field['bundles']);

          /** @var \Drupal\field\Entity\FieldConfig $field_settings */
          $field_settings = $this->entityFieldManager
            ->getFieldDefinitions(
              $this->view->getBaseEntityType()->id(),
              $bundle
            )[$field_name];

          $form['#attached']['drupalSettings']['preloaded_entries'][$field_name] = $this->buildPreLoaded(
            $count,
            $field_settings
          );
        }
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function exposedFormSubmit(&$form, FormStateInterface $form_state, &$exclude) {
    parent::exposedFormSubmit($form, $form_state, $exclude);

    // Additional code to make initial values
    // work correctly with exposed filters.
    $map = $this->entityFieldManager
      ->getFieldMapByFieldType('entity_reference');

    foreach ($form_state->getValues() as $name => $value) {
      if (static::isEntityReferenceField($name) && !empty($value) && is_array($value)) {
        $field_name = static::convertDatabaseFieldToFieldname($name);
        $field      = $map[$this->view->getBaseEntityType()->id()][$field_name];
        $bundle     = reset($field['bundles']);

        /** @var \Drupal\field\Entity\FieldConfig $field_settings */
        $field_settings = $this->entityFieldManager
          ->getFieldDefinitions(
            $this->view->getBaseEntityType()->id(),
            $bundle
          )[$field_name];

        $entities = $this->entityTypeManager
          ->getStorage($field_settings->getSetting('target_type'))
          ->loadMultiple($value);

        $values = [];

        if (!empty($entities)) {
          $values = array_map(function ($entity) {
            /** @var \Drupal\Core\Entity\EntityInterface $entity */
            return $entity->label();
          }, $entities);
        }

        $form['#attached']['drupalSettings']['initValues'][$field_name] = $values;
      }
    }
  }

}
