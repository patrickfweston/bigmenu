<?php

/**
 * @file
 * Contains \Drupal\bigmenu\MenuFormController.
 */

namespace Drupal\bigmenu;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\menu_ui\MenuForm as DefaultMenuFormController;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Render\Element;

/**
 * Class MenuFormController
 * @package Drupal\bigmenu
 */
class MenuFormController extends DefaultMenuFormController
{

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param int $depth
   * @param NULL $menuOpen
   * @return array
   */
  protected function buildOverviewForm(array &$form, FormStateInterface $form_state, $depth = 1, \Drupal\menu_link_content\Entity\MenuLinkContent $menu_link = NULL)
  {
//    $menu_link = 'menu_link_content:eeb3b069-6a58-4b8b-9915-59559e240201';
    // Ensure that menu_overview_form_submit() knows the parents of this form
    // section.
    if (!$form_state->has('menu_overview_form_parents')) {
      $form_state->set('menu_overview_form_parents', []);
    }

    // Use Menu UI adminforms
    $form['#attached']['library'][] = 'menu_ui/drupal.menu_ui.adminforms';

    $form['links'] = array(
      '#type' => 'table',
      '#theme' => 'table__menu_overview',
      '#header' => array(
        $this->t('Menu link'),
        array(
          'data' => $this->t('Enabled'),
          'class' => array('checkbox'),
        ),
        $this->t('Weight'),
        array(
          'data' => $this->t('Operations'),
          'colspan' => 3,
        ),
      ),
      '#attributes' => array(
        'id' => 'menu-overview',
      ),
      '#tabledrag' => array(
        array(
          'action' => 'match',
          'relationship' => 'parent',
          'group' => 'menu-parent',
          'subgroup' => 'menu-parent',
          'source' => 'menu-id',
          'hidden' => TRUE,
          'limit' => \Drupal::menuTree()->maxDepth() - 1,
        ),
        array(
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'menu-weight',
        ),
      ),
    );

    // No Links available (Empty menu)
    $form['links']['#empty'] = $this->t('There are no menu links yet. <a href=":url">Add link</a>.', [
      ':url' => $this->url('entity.menu.add_link_form', ['menu' => $this->entity->id()], [
        'query' => ['destination' => $this->entity->url('edit-form')],
      ]),
    ]);

    $tree_params = new MenuTreeParameters();
    $tree_params->setMaxDepth($depth);

    if ($menu_link) {
      $tree_params->setRoot($menu_link->getPluginId());
    }

    $tree = $this->menuTree->load($this->entity->id(), $tree_params);

    // We indicate that a menu administrator is running the menu access check.
    $this->getRequest()->attributes->set('_menu_admin', TRUE);
    $manipulators = array(
      array('callable' => 'menu.default_tree_manipulators:checkAccess'),
      array('callable' => 'menu.default_tree_manipulators:generateIndexAndSort'),
    );
    $tree = $this->menuTree->transform($tree, $manipulators);
    $this->getRequest()->attributes->set('_menu_admin', FALSE);

    // Determine the delta; the number of weights to be made available.
    $count = function (array $tree) {
      $sum = function ($carry, MenuLinkTreeElement $item) {
        return $carry + $item->count();
      };
      return array_reduce($tree, $sum);
    };

    // Tree maximum or 50.
    $delta = max($count($tree), 50);

    $links = $this->buildOverviewTreeForm($tree, $delta);

    $this->process_links($form, $links, $menu_link);

    return $form;
  }

  public function process_links(&$form, $links, $menu_link) {
    foreach (Element::children($links) as $id) {
      if (isset($links[$id]['#item']) && !isset($form['links'][$id]['#item'])) {
        $element = $links[$id];

        $form['links'][$id]['#item'] = $element['#item'];

        // TableDrag: Mark the table row as draggable.
        $form['links'][$id]['#attributes'] = $element['#attributes'];
        $form['links'][$id]['#attributes']['class'][] = 'draggable';

        // TableDrag: Sort the table row according to its existing/configured weight.
        $form['links'][$id]['#weight'] = $element['#item']->link->getWeight();

        // Add special classes to be used for tabledrag.js.
        $element['parent']['#attributes']['class'] = array('menu-parent');
        $element['weight']['#attributes']['class'] = array('menu-weight');
        $element['id']['#attributes']['class'] = array('menu-id');

        $form['links'][$id]['title'] = array(
          array(
            '#theme' => 'indentation',
            '#size' => $element['#item']->depth - 1,
          ),
          $element['title'],
        );
        $form['links'][$id]['enabled'] = $element['enabled'];
        $form['links'][$id]['enabled']['#wrapper_attributes']['class'] = array('checkbox', 'menu-enabled');

        $form['links'][$id]['weight'] = $element['weight'];

        // Operations (dropbutton) column.
        $form['links'][$id]['operations'] = $element['operations'];

        $form['links'][$id]['id'] = $element['id'];
        $form['links'][$id]['parent'] = $element['parent'];

        $mlid = (int)$links[$id]['#item']->link->getMetaData()['entity_id'];

        if ($form['links'][$id]['#item']->hasChildren) {
          if (!$menu_link || $menu_link->id() != $mlid) {
            $form['links'][$id]['title'][] = array(
              '#type' => 'big_menu_button',
              '#title' => t('Show Children'),
              '#value' => 'Edit Children',
              '#name' => $mlid,
              '#attributes' => array('mlid' => $mlid),
              '#url' => '#',
              '#description' => t('Show children'),
              '#ajax' => array(
                // Function to call when event on form element triggered.
                'callback' => array(
                  $this,
                  'Drupal\bigmenu\MenuFormController::bigmenu_ajax_callback'
                ),
                // Effect when replacing content. Options: 'none' (default), 'slide', 'fade'.
                'effect' => 'none',
                // Javascript event to trigger Ajax. Currently for: 'onchange'.
                'event' => 'click',
                'progress' => array(
                  // Graphic shown to indicate ajax. Options: 'throbber' (default), 'bar'.
                  'type' => 'throbber',
                  // Message to show along progress graphic. Default: 'Please wait...'.
                  'message' => NULL,
                ),
              ),
            );
          }
        }
      }
    }
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @return AjaxResponse
   */
  public function bigmenu_ajax_callback(array &$form, \Drupal\Core\Form\FormStateInterface &$form_state)
  {
    $elem = $form_state->getTriggeringElement();
    $menuLinkId = $elem['#attributes']['mlid'];

    $menu_link = \Drupal::entityTypeManager()->getStorage('menu_link_content')->load($menuLinkId);

    // Instantiate an AjaxResponse Object to return.
    $ajax_response = new AjaxResponse();

    // Add a command to execute on form, jQuery .html() replaces content between tags.
    // In this case, we replace the description with whether the username was found or not.
    $ajax_response->addCommand(new HtmlCommand('form#menu-edit-bigmenu-form', $this->buildOverviewForm($form, $form_state, 15, $menu_link)));

    // Return the AjaxResponse Object.
    return $ajax_response;
  }

//  private function reorder_links($links, $menu_plugin_id) {
//    $temp_links_before = array();
//    $temp_links_after = array();
//
//    $after = false;
//    foreach (Element::children($links) as $id) {
//      $curr_id = $links[$id]['#item']->link->getPluginId();
////      drupal_set_message($curr_id . "  " . $menu_plugin_id);
//      if ($curr_id == $menu_plugin_id) {
//        $after = true;
//      }
//      if ($after) {
//        $temp_links_after[] = $links[$id];
//      } else {
//        $temp_links_before[] = $links[$id];
//      }
//    }
//
////    drupal_set_message(count($temp_links_after) . "  " . count($temp_links_before));
//
//    return array_merge($temp_links_before, $temp_links_after);
//  }
}
