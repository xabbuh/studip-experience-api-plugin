<?php

namespace Xabbuh\ExperienceApiPlugin\Model;

/**
 * An Experience API learning record store.
 *
 * @author Christian Flothmann <christian.flothmann@xabbuh.de>
 *
 * @property \Course $course
 */
class LearningRecordStore extends \SimpleORMap
{
    public function __construct($id = null)
    {
        $this->db_table = 'xapi_lrs';

        parent::__construct($id);
    }
}
