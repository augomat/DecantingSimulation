<?php
/**
 * Created by PhpStorm.
 * User: User
 * Date: 07.02.2019
 * Time: 07:51
 */

class Unit {
    private $strName;
    private $flagClosed = false;
    public $arrCompartments = array();
    public function __construct($strName) { $this->strName = $strName; }
    public function getName() { return $this->strName; }
    public function getIsClosed() { return $this->flagClosed; }
    public function setIsClosed($flagIsclosed) { $this->flagClosed = $flagIsclosed; }
}
class Compartment {
    private $strName;
    public $intMaxValue;
    private $intFilled;
    public function __construct($strName) { $this->strName = $strName; }
    public function addStock($intQty) {
        if ($this->intFilled + $intQty > $this->intMaxValue)
            throw new Exception("Too much stock");
        $this->intFilled += $intQty;
    }
    public function getQty() { return $this->intFilled; }
    public function getRemainingQty() { return $this->intMaxValue - $this->intFilled; }
    public function getMaxQty() { return $this->intMaxValue; }
    public function getName() { return $this->strName; }

}
class Runner {
    static $PRINT_UNIT_STATUS_FOR_EVERY_ACTION = true;

    const TIME_TO_EMPTY = 2.0;
    const TIME_PER_COUNTED_PIECE = 1.5; //0.5
    const TIME_PER_SPLIT = 0.0;
    const TIME_NEW_TOTE = 20.0; //open and close //8.0
    const TIME_NEW_COMPARTMENT = 3.0; //3.0

    const COMPARTMENT_SIZES = array(100, 50, 25);
    public $arrUnits = array();
    private $arrStats = array();
    public function runMultiple()
    {
        self::$PRINT_UNIT_STATUS_FOR_EVERY_ACTION = false;
        $arrAllStats = array();
        for($i = 0.50; $i <= 0.701; $i += 0.03) {
            $arrStats = $this->runInternal(new TargetFilldegree($i, 123456));
            $arrTimeTotal = array_column($arrStats, 'timeTotal');
            $arrAllStats['TargetDegree'][(string)$i]['targetFilldegree'] = $i;
            $arrAllStats['TargetDegree'][(string)$i]['timeTotalAvg'] = array_sum($arrTimeTotal) / count($arrTimeTotal);
            $arrAllStats['TargetDegree'][(string)$i]['timeTotalMax'] = max($arrTimeTotal);
        }
        $this->writeOutOverallStats($arrAllStats, array('targetFilldegree', 'timeTotalAvg', 'timeTotalMax'), 'SummedStats.csv');
    }
    public function run() {
        //$arrAllStats['MaxFill'] = $this->runInternal(new MaximizeFilldegree());
        //$arrAllStats['FullyDecant'] = $this->runInternal(new MaximizeFullyDecantable());
        //$arrAllStats['MinExpSplits'] = $this->runInternal(new MinimizeExpensiveSplits(20));
        $arrAllStats['TargetDegree'] = $this->runInternal(new TargetFilldegree(0.8, 1));

        //$arrExportCols = array('avgFilldegree', 'timeTotal');
        $arrExportCols = array('timeTotal');
        //$arrExportCols = array('intSteps', 'timeTotal');
        //$arrExportCols = array('timeToEmpty', 'timeCounting', 'timeNewTote', 'timeNewCompartment');
        $this->writeOutOverallStats($arrAllStats, $arrExportCols);
    }
    public function runInternal(IRecommender $objRecommender) {
        $this->arrStats = array();
        echo "Recommender: ".$objRecommender->getName()."<br />";
        for ($intCartonSize = 1; $intCartonSize <= self::COMPARTMENT_SIZES[0]; $intCartonSize++) {
            $this->arrUnits = array();
            $intCountingSplits = 0;
            $intCountingSplitsQty = 0;
            $intDecantSteps = 0;
            $intMaxNeededPutIntoSplit = 0;
            $intMaxNeededRemainingSplit = 0;
            $intMinNeededPutIntoSplit = self::COMPARTMENT_SIZES[0];
            $intMinNeededRemainingSplit = self::COMPARTMENT_SIZES[0];
            $intSmallestCompartment = self::COMPARTMENT_SIZES[count(self::COMPARTMENT_SIZES)-1]; //to fill smallest comp to 100% with carton size 1
            for ($countCarton = 0; $countCarton < $intSmallestCompartment; $countCarton++) {
                for ($intCartonQtyLeft = $intCartonSize; $intCartonQtyLeft > 0;) {
                    $objCompartment = $objRecommender->suggestCompartment($this->arrUnits, $intCartonQtyLeft);
                    if (!$objCompartment) {
                        $objRecommender->closeTarget($this->arrUnits);
                        $objUnit = $this->createNewUnit($intCartonSize);
                        $objCompartment = $objUnit->arrCompartments[0];
                    }
                    $intDecantQty = min($objCompartment->getRemainingQty(), $intCartonQtyLeft);

                    echo "Carton $countCarton: Putting {$intDecantQty}/{$intCartonQtyLeft} into {$objCompartment->getName()} (".($objCompartment->getQty()+$intDecantQty)."/{$objCompartment->getMaxQty()})";
                    $flagWasCountingSplit = false;
                    $strCountingSplitInfo = "";
                    if ($intCartonQtyLeft > $objCompartment->getRemainingQty()) {
                        $flagWasCountingSplit = true;
                        $intCountingSplits++;
                        $intPutIntosplit = min($intCartonQtyLeft, $objCompartment->getRemainingQty());
                        $intRemainingSplit = $intCartonQtyLeft - $intPutIntosplit;
                        $intSplitCount = min($intPutIntosplit, $intRemainingSplit); //split up the lesser qty
                        $intCountingSplitsQty += $intSplitCount;

                        $strCountingSplitInfo = "Counting split ($intSplitCount) ($intPutIntosplit | $intRemainingSplit)";
                        $intMaxNeededPutIntoSplit = max($intPutIntosplit, $intMaxNeededPutIntoSplit);
                        $intMaxNeededRemainingSplit = max($intRemainingSplit, $intMaxNeededRemainingSplit);
                        $intMinNeededPutIntoSplit = min($intPutIntosplit, $intMinNeededPutIntoSplit);
                        $intMinNeededRemainingSplit = min($intRemainingSplit, $intMinNeededRemainingSplit);
                    }
                    $intDecantSteps++;

                    $objCompartment->addStock($intDecantQty);
                    $intCartonQtyLeft -= $intDecantQty;

                    if (self::$PRINT_UNIT_STATUS_FOR_EVERY_ACTION)
                        $this->printUnitStatus(); //really slow!!!!

                    if ($flagWasCountingSplit)
                        echo " ".$strCountingSplitInfo;

                    echo "<br />";
                }
            }
            $this->saveStats($intCartonSize, $intDecantSteps, $intCountingSplits, $intCountingSplitsQty, $intMaxNeededPutIntoSplit, $intMaxNeededRemainingSplit, $intMinNeededPutIntoSplit, $intMinNeededRemainingSplit);
        }
        $this->writeOutStats($objRecommender->getName().".csv");
        return $this->arrStats;
    }
    protected function printUnitStatus()
    {
        $str = " ";
        foreach ($this->arrUnits as $objUnit) {
            $str .= ($objUnit->getIsClosed()) ? htmlentities("<") : htmlentities("(");
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                $str .= "|" . $objCompartment->getQty() . "|";
            }
            $str .= ($objUnit->getIsClosed()) ? htmlentities("> ") : htmlentities(") ");
        }
        echo $str;
    }
    protected function saveStats($intCartonSize, $intDecantSteps, $intCountingSplits, $intCountingSplitsQty, $intMaxNeededPutIntoSplit, $intMaxNeededRemainingSplit, $intMinNeededPutIntoSplit, $intMinNeededRemainingSplit)
    {
        $this->arrStats[$intCartonSize]['intSteps'] = $intDecantSteps;
        $this->arrStats[$intCartonSize]['intCountingSplits'] = $intCountingSplits;
        $this->arrStats[$intCartonSize]['intCountingSplitsQty'] = $intCountingSplitsQty;
        $this->arrStats[$intCartonSize]['intCountTargetUnits'] = count($this->arrUnits);
        $arrFilldegrees = array();
        $intCountCompartmentsUsed = 0;
        $intCountCompartments = 0;
        foreach($this->arrUnits as $objUnit) {
            foreach($objUnit->arrCompartments as $objCompartment) {
                if ($objCompartment->getQty() > 0) {
                    $arrFilldegrees[] = $objCompartment->getQty() / $objCompartment->getMaxQty();
                    $intCountCompartmentsUsed++;
                }
                $intCountCompartments++;
            }
        }
        $this->arrStats[$intCartonSize]['avgFilldegree'] = str_replace('.', ',', round(100*array_sum($arrFilldegrees)/$intCountCompartmentsUsed, 0));
        $intStepsWithoutCounting = $intDecantSteps - $intCountingSplits;
        $intCountCompartmentsErected = $intCountCompartments - count($this->arrUnits);
        $doubleTimeToEmpty = $intStepsWithoutCounting * self::TIME_TO_EMPTY;
        $doubleTimePerSplit = $intCountingSplits * self::TIME_PER_SPLIT;
        $doubleTimeCounting = $intCountingSplitsQty * self::TIME_PER_COUNTED_PIECE;
        $doubleTimeNewTote = count($this->arrUnits) * self::TIME_NEW_TOTE;
        $doubleTimeNewCompartment = $intCountCompartmentsErected * self::TIME_NEW_COMPARTMENT;
        $doubleTimteTotal = $doubleTimeToEmpty + $doubleTimePerSplit + $doubleTimeCounting + $doubleTimeNewTote + $doubleTimeNewCompartment;
        $this->arrStats[$intCartonSize]['timeToEmpty'] = $doubleTimeToEmpty;
        $this->arrStats[$intCartonSize]['timePerSplit'] = $doubleTimePerSplit;
        $this->arrStats[$intCartonSize]['timeCounting'] = $doubleTimeCounting;
        $this->arrStats[$intCartonSize]['timeNewTote'] = $doubleTimeNewTote;
        $this->arrStats[$intCartonSize]['timeNewCompartment'] = $doubleTimeNewCompartment;
        $this->arrStats[$intCartonSize]['timeTotal'] = (int)($doubleTimteTotal/ (self::COMPARTMENT_SIZES[0]/14)); //arbitrary factor, just for scaling, ~7sec for 100, 14 for 200, ...
        $this->arrStats[$intCartonSize]['maxNeededPutIntoSplit'] = $intMaxNeededPutIntoSplit;
        $this->arrStats[$intCartonSize]['maxNeededRemainingSplit'] = $intMaxNeededRemainingSplit;
        $this->arrStats[$intCartonSize]['maxNeeded'] = max($intMaxNeededPutIntoSplit, $intMaxNeededRemainingSplit);
        $this->arrStats[$intCartonSize]['minNeededPutIntoSplit'] = $intMinNeededPutIntoSplit;
        $this->arrStats[$intCartonSize]['minNeededRemainingSplit'] = $intMinNeededRemainingSplit;
        $this->arrStats[$intCartonSize]['minNeeded'] = min($intMinNeededPutIntoSplit, $intMinNeededRemainingSplit);

    }
    protected function writeOutStats($strFilename)
    {
        file_put_contents($strFilename, "CartonSize;".implode(';', array_keys($this->arrStats[10]))."\r\n");
        foreach($this->arrStats as $intCartonsSize => $arrStat) {
            array_walk($arrStat, array($this, 'convertDecimalPoint'));
            file_put_contents($strFilename, $intCartonsSize . ';' . implode(';', $arrStat) . "\r\n", FILE_APPEND);
        }
        echo "Stats written to: $strFilename<br />";
    }
    protected function writeOutOverallStats($arrAllStats, $arrExportCols, $strOverallFilename = 'OverallStats.csv') {
        $arrHeader = array();
        $arrVals = array();
        $intCurrentCol = 0;
        foreach ($arrAllStats as $strStrategy => $arrStatsPerStrategy) {
            foreach($arrExportCols as $strExportCol)
                $arrHeader[] = "$strStrategy:$strExportCol";
            foreach ($arrStatsPerStrategy as $intRow => $row) {
                foreach($arrExportCols as $intColIndex => $strExportCol)
                    $arrVals[$intRow][$intCurrentCol+$intColIndex] = $row[$strExportCol];
            }
            $intCurrentCol += count($arrExportCols);
        }
        file_put_contents($strOverallFilename, implode(";", $arrHeader)."\r\n");
        foreach ($arrVals as $row) {
            array_walk($row, array($this, 'convertDecimalPoint'));
            file_put_contents($strOverallFilename, implode(";", $row) . "\r\n", FILE_APPEND);
        }
        echo "Overall stats written to: $strOverallFilename<br />";
    }
    private function convertDecimalPoint(&$value, &$key) {
        $arrParts = explode('.', $value);
        if (count($arrParts) != 2) return;
        if ((int)$arrParts[0] != 0 and (int)$arrParts[1] > 0)
            $value = str_replace('.', ',', $value);
    }
    public function createNewUnit($countItems) : Unit {
        $intBestSize = self::COMPARTMENT_SIZES[0];
        foreach (self::COMPARTMENT_SIZES as $intSize) {
            if ($countItems <= $intSize)
                $intBestSize = $intSize;
        }
        if (self::COMPARTMENT_SIZES[0] % $intBestSize != 0) die("Wrong compartment definition");
        $countCommpartments = self::COMPARTMENT_SIZES[0] / $intBestSize;

        $objUnit = new Unit("U".count($this->arrUnits));
        for ($i = 0; $i < $countCommpartments; $i++) {
          $objCompartment = new Compartment($objUnit->getName()."_".$i);
          $objCompartment->intMaxValue = (int)floor(self::COMPARTMENT_SIZES[0] / $countCommpartments);
          $objUnit->arrCompartments[] = $objCompartment;
        }
        $this->arrUnits[] = $objUnit;
        return $objUnit;
    }
}
interface IRecommender {
    public function suggestCompartment(&$arrUnits, $intDemandedQty);
    public function closeTarget(&$arrUnits);
    public function getName();
}
class MaximizeFilldegree implements IRecommender {
    public function getName() { return "MaximizeFilldegree"; }
    public function closeTarget(&$arrUnits) { }
    public function suggestCompartment(&$arrUnits, $intQty) {
        foreach ($arrUnits as $objUnit) {
            /** @var Unit $objUnit */
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getQty() > 0 and $objCompartment->getRemainingQty() > 0)
                    return $objCompartment;
            }
        }
        foreach ($arrUnits as $objUnit) {
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getQty() == 0)
                    return $objCompartment;
            }
        }
        return null;
    }
}
class MaximizeFullyDecantable implements IRecommender {
    public function getName() { return "MaxFullyDecant"; }
    public function closeTarget(&$arrUnits) { }
    public function suggestCompartment(&$arrUnits, $intQty) {
        $flagFullyDecantableInBiggestTarget = $intQty <= Runner::COMPARTMENT_SIZES[0];
        foreach ($arrUnits as $objUnit) {
            /** @var Unit $objUnit */
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getQty() > 0 and $objCompartment->getRemainingQty() > 0
                    and ($objCompartment->getRemainingQty() >= $intQty or !$flagFullyDecantableInBiggestTarget))
                    return $objCompartment;
            }
        }
        foreach ($arrUnits as $objUnit) {
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getQty() == 0)
                    return $objCompartment;
            }
        }
        return null;
    }
}
// Idea is that if we are able to decant a lot (but not all) in 1 step, do it
class MinimizeExpensiveSplits implements IRecommender {
    private $intSplitUnderNumCounts = 20;
    public function __construct($intSplitUnderNumCounts){ $this->intSplitUnderNumCounts = $intSplitUnderNumCounts; }
    public function getName() { return "MinExpSplits"; }
    public function closeTarget(&$arrUnits) { }
    public function suggestCompartment(&$arrUnits, $intQty) {
        //If fully decantable return, else find compartment with most remaining space
        $flagFullyDecantableInBiggestTarget = $intQty <= Runner::COMPARTMENT_SIZES[0];
        $objMostFreeCompartment = null;
        $intCurrentMaxRemaining = 0;
        foreach ($arrUnits as $objUnit) {
            /** @var Unit $objUnit */
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getRemainingQty() > $intCurrentMaxRemaining) {
                    $objMostFreeCompartment = $objCompartment;
                    $intCurrentMaxRemaining = $objCompartment->getRemainingQty();
                }
                if ($objCompartment->getQty() > 0 and $objCompartment->getRemainingQty() > 0
                    and ($objCompartment->getRemainingQty() >= $intQty or !$flagFullyDecantableInBiggestTarget))
                    return $objCompartment;
            }
        }

        //If split qty is under threshold return
        //always take the bigger half because otherwise the split could be very non-beneficial (e.g. split up 1 (very easy) into a target with remaining qty 1 doesn't make sense)
        //but formula evalutes against smaller half because this is effectively the number we need to count
        if ($objMostFreeCompartment != null and $intQty - $objMostFreeCompartment->getRemainingQty() <= $this->intSplitUnderNumCounts)
            return $objMostFreeCompartment;

        return null;
    }
}
// Calculates which splits are ok to reach the given compartment target filldegree
class TargetFilldegree implements IRecommender {
    private $dblTargetFilldegreeFactor = 0.7;
    private $intMaxOpenedTargets;
    public function __construct($dblTargetFilldegreeFactor, $intMaxOpenedTargets = 1){
        $this->dblTargetFilldegreeFactor = $dblTargetFilldegreeFactor;
        $this->intMaxOpenedTargets = $intMaxOpenedTargets; }
    public function getName() { return "TargetFilldegree"; }
    public function closeTarget(&$arrUnits) {
        if (count($arrUnits) > $this->intMaxOpenedTargets - 1) $arrUnits[count($arrUnits)-1]->setIsClosed(true);
    }
    public function suggestCompartment(&$arrUnits, $intQty) {
        //If fully decantable return, else find compartment with most remaining space
        $flagFullyDecantableInBiggestTarget = $intQty <= Runner::COMPARTMENT_SIZES[0];
        $objMostFreeCompartment = null;
        $intCurrentMaxRemaining = 0;
        foreach ($arrUnits as $objUnit) {
            /** @var Unit $objUnit */
            if ($objUnit->getIsClosed()) continue;
            foreach ($objUnit->arrCompartments as $objCompartment) {
                /** @var $objCompartment Compartment */
                if ($objCompartment->getRemainingQty() > $intCurrentMaxRemaining) {
                    $objMostFreeCompartment = $objCompartment;
                    $intCurrentMaxRemaining = $objCompartment->getRemainingQty();
                }
                //Kann ich irgendwo fully decant ZULGERN?
                if ($objCompartment->getQty() > 0 and $objCompartment->getRemainingQty() > 0
                    and ($objCompartment->getRemainingQty() >= $intQty or !$flagFullyDecantableInBiggestTarget))
                    return $objCompartment;
            }
        }

        // Possiblle to fully decant into a new compartment
        if ($objMostFreeCompartment != null and $objMostFreeCompartment->getRemainingQty() >= $intQty)
            return $objMostFreeCompartment;

        //If split qty is under threshold return
        if ($objMostFreeCompartment != null) {

            //Calculate current filldegree
            $intTotalDecanted = $intTotalPossible = 0;
            foreach ($arrUnits as $objUnit) {
                /** @var Unit $objUnit */
                foreach ($objUnit->arrCompartments as $objCompartment) { /** @var $objCompartment Compartment */
                    if ($objCompartment->getQty() > 0) {
                        $intTotalDecanted += $objCompartment->getQty();
                        $intTotalPossible += $objCompartment->getMaxQty();
                    }
                }
            }
            $dblCurrentFilldegree = $intTotalDecanted / $intTotalPossible;

            if ($dblCurrentFilldegree < $this->dblTargetFilldegreeFactor)
                return $objMostFreeCompartment;
        }

        return null;
    }
}
$objRunner = new Runner();
$objRunner->runMultiple();
//$objRunner->run();