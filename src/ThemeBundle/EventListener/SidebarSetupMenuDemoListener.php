<?php
/**
 * SidebarSetupMenuDemoListener.php
 * avanzu-admin
 * Date: 23.02.14.
 */

namespace Chamilo\ThemeBundle\EventListener;

use Chamilo\ThemeBundle\Event\SidebarMenuEvent;
use Chamilo\ThemeBundle\Model\MenuItemModel;
use Symfony\Component\HttpFoundation\Request;

class SidebarSetupMenuDemoListener
{
    public function onSetupMenu(SidebarMenuEvent $event)
    {
        $request = $event->getRequest();

        foreach ($this->getMenu($request) as $item) {
            $event->addItem($item);
        }
    }

    protected function getMenu(Request $request)
    {
        $earg = [];
        $rootItems = [
            $dash = new MenuItemModel('dashboard', 'Dashboard', 'avanzu_admin_dash_demo', $earg, 'fa fa-dashboard'),
            $form = new MenuItemModel('forms', 'Forms', 'avanzu_admin_form_demo', $earg, 'fa fa-edit'),
            $widgets = new MenuItemModel('widgets', 'Widgets', 'avanzu_admin_demo', $earg, 'fa fa-th', 'new'),
            $ui = new MenuItemModel('ui-elements', 'UI Elements', '', $earg, 'fa fa-laptop'),
        ];

        $ui->addChild(new MenuItemModel('ui-elements-general', 'General', 'avanzu_admin_ui_gen_demo', $earg))
            ->addChild($icons = new MenuItemModel('ui-elements-icons', 'Icons', 'avanzu_admin_ui_icon_demo', $earg));

        return $this->activateByRoute($request->get('_route'), $rootItems);
    }

    /**
     * @param MenuItemModel[] $items
     */
    protected function activateByRoute($route, array $items)
    {
        foreach ($items as $item) { /** @var MenuItemModel $item */
            if ($item->hasChildren()) {
                $this->activateByRoute($route, $item->getChildren());
            } else {
                if ($item->getRoute() == $route) {
                    $item->setIsActive(true);
                }
            }
        }

        return $items;
    }
}
