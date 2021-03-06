<?php
/**
 * Pi module installer action
 *
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @copyright       Copyright (c) Pi Engine http://www.xoopsengine.org
 * @license         http://www.xoopsengine.org/license New BSD License
 * @author          Taiwen Jiang <taiwenjiang@tsinghua.org.cn>
 * @package         Module\System
 * @subpackage      Installer
 */

namespace Module\System\Installer\Action;

use Pi;
use Pi\Application\Installer\Action\Install as BasicInstall;
use Pi\Application\Installer\SqlSchema;
use Pi\Application\Installer\Theme as ThemeInstaller;
use Pi\Application\Installer\Module as ModuleInstaller;
use Zend\EventManager\Event;

class Install extends BasicInstall
{
    /**
     * Modules to be installed upon system installation
     *
     * @var array
     */
    protected $preInstalledModules = array('page', 'widget');

    /**
     * {@inheritDoc}
     */
    protected function attachDefaultListeners()
    {
        $events = $this->events;
        $events->attach('install.pre', array($this, 'createSystemSchema'), 1000);
        $events->attach('install.post', array($this, 'installTheme'), 1);
        $events->attach('install.post', array($this, 'createSystemData'), -10);
        $events->attach('install.post', array($this, 'installApplication'), -100);
        $events->attach('install.post', array($this, 'dressupBlock'), -200);
        parent::attachDefaultListeners();
        return $this;
    }

    /**
     * Generate system data
     *
     * @param Event $e
     */
    public function createSystemData(Event $e)
    {
        $module = $e->getParam('module');
        $message = array();

        // Add default taxonomy domain
        Pi::service('taxonomy')->addDomain(array(
            'name'          => 'taxon',
            'title'         => __('Default taxonomy'),
            'description'   => __('Default global taxonomy domain. Not allowed to change.'),
        ), false);


        // Add system messages
        $type       = 'admin-welcome';
        $message    = array(
            'content'   => __('Welcome to Pi powered system.'),
            'time'      => time(),
        );
        $row = Pi::model('user_repo')->createRow(array(
            'module'    => $module,
            'type'      => $type,
            'content'   => $message,
        ));
        $row->save();

        // Add quick links
        $user   = 1;
        $type   = 'admin-link';
        $links  = array(
            array(
                'title' => 'Pi Engine Development',
                'url'   => 'http://www.pialog.org',
            ),
            array(
                'title' => 'Pi Engine Code',
                'url'   => 'http://github.com/pi-engine/pi',
            ),
            array(
                'title' => 'Pi Engine Doc',
                'url'   => 'https://github.com/pi-engine/pi/wiki',
            ),
            array(
                'title' => 'Pi Engine Twitter',
                'url'   => 'https://twitter.com/PiEnable',
            ),
        );

        $row = Pi::model('user_repo')->createRow(array(
            'user'      => $user,
            'module'    => $module,
            'type'      => $type,
            'content'   => $links,
        ));
        $row->save();

        // Add update list
        $model = Pi::model('update', $module);
        $data = array(
            'title'     => __('System installed'),
            'content'   => __('The system is installed successfully.'),
            'uri'       => Pi::url('www', true),
            'time'      => time(),
        );
        $model->insert($data);
    }

    /**
     * Install default theme
     *
     * @param Event $e
     * @return bool
     */
    public function installTheme(Event $e)
    {
        $themeInstaller = new ThemeInstaller;
        $result = $themeInstaller->install('default');
        if (is_array($result)) {
            $status = $result['status'];
            if (!$status) {
                $ret = $e->getParam('result');
                $ret['theme'] = $result;
                $e->setParam('result', $ret);
            }
        } else {
            $status = (bool) $result;
        }
        return $status;
    }

    /**
     * Create system module data
     *
     * @param Event $e
     * @return bool
     */
    public function createSystemSchema(Event $e)
    {
        $sqlFile = Pi::path('module') . '/system/sql/mysql.system.sql';
        $status = SqlSchema::query($sqlFile);

        return $status;
    }

    /**
     * Install modules automatically
     *
     * @param Event $e
     * @return bool
     */
    public function installApplication(Event $e)
    {
        $apps = $this->preInstalledModules;
        //$installer = new ModuleInstaller;
        foreach ($apps as $app) {
            $installer = new ModuleInstaller;
            $ret = $installer->install($app);
        }

        return true;
    }

    /**
     * Install and dress up pages with blocks
     *
     * @param Event $e
     */
    public function dressupBlock(Event $e)
    {
        // Find homepage
        $modelPage = Pi::model('page');
        $homePage = $modelPage->select(array(
            'section'       => 'front',
            'block'         => 1,
            'module'        => 'system',
            'controller'    => 'index',
            'action'        => 'index',
        ))->current()->toArray();

        // Add user login block to homepage sidebar
        $modelBlock = Pi::model('block');
        $blockList = $modelBlock->select(array(
            'module'    => 'system',
            'name'      => array('system-user', 'system-login')
        ));
        //$blocks = array();
        $i = 0;
        $modelLink = Pi::model('page_block');
        foreach ($blockList as $block) {
            //$blocks[$block['name']] = $block['id'];
            //foreach ($pages as $page) {
                $data = array(
                    'page'      => $homePage['id'],
                    'block'     => $block['id'],
                    'zone'      => 8,
                    'order'     => ++$i
                );
                $modelLink->insert($data);
            //}
        }

        // Add spotlight as top block to homepage
        $blockList = array();

        if (in_array('widget', $this->preInstalledModules)) {
            // Add spotlight and feature blocks to homepage
            $blockList[] = $modelBlock->select(array(
                'module'    => 'widget',
                'name'      => 'widget-highlights',
            ))->current()->toArray();
        }

        $i = 0;
        foreach ($blockList as $block) {
            $data = array(
                'page'      => $homePage['id'],
                'block'     => $block['id'],
                'zone'      => 0,
                'order'     => ++$i
            );
            $modelLink->insert($data);
        }


        // Add feature as center block to homepage
        $blockList = array();
        $blockList[] = $modelBlock->select(array(
            'module'    => 'system',
            'name'      => 'system-pi'
        ))->current()->toArray();

        $i = 0;
        foreach ($blockList as $block) {
            $data = array(
                'page'      => $homePage['id'],
                'block'     => $block['id'],
                'zone'      => 2,
                'order'     => ++$i
            );
            $modelLink->insert($data);
        }

    }

}
