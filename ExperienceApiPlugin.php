<?php

/*
 * This file is part of the Stud.IP Experience API plugin.
 *
 * (c) Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require __DIR__.'/vendor/autoload.php';

/**
 * The Stud.IP Experience API plugin.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 */
class ExperienceApiPlugin extends StudIPPlugin implements SystemPlugin
{
    /**
     * {@inheritdoc}
     */
    public function perform($unconsumedPath)
    {
        require_once 'app/controllers/studip_controller.php';

        $dispatcher = new Trails_Dispatcher(
            $this->getPluginPath(),
            rtrim(PluginEngine::getLink($this, array(), null), '/'),
            null
        );
        $dispatcher->dispatch($unconsumedPath);
    }
}
