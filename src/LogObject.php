<?php

namespace eNTiDi\LogObject;

use LogicException;
use SilverStripe\Model\ModelData;
use SilverStripe\ORM\DataObjectInterface;

class LogObject extends ModelData implements DataObjectInterface
{
    public function delete()
    {
        throw new LogicException('Existing LogObject entries cannot be deleted.');
    }
}
