<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

namespace Gibbon\Module\FreeLearning;

use ZipArchive;
use Gibbon\Contracts\Services\Session;
use Gibbon\Module\FreeLearning\Domain\UnitGateway;
use Gibbon\Module\FreeLearning\Domain\UnitBlockGateway;
use Gibbon\Module\FreeLearning\Domain\UnitAuthorGateway;

class UnitImporter 
{
    protected $gibbonDepartmentIDList;
    protected $course;

    protected $override = false;
    protected $files;

    protected $unitGateway;
    protected $unitBlockGateway;
    protected $unitAuthorGateway;
    protected $session;

    public function __construct(UnitGateway $unitGateway, UnitBlockGateway $unitBlockGateway, UnitAuthorGateway $unitAuthorGateway, Session $session)
    {
        $this->unitGateway = $unitGateway;
        $this->unitBlockGateway = $unitBlockGateway;
        $this->unitAuthorGateway = $unitAuthorGateway;
        $this->session = $session;
    }

    public function setOverride($override)
    {
        $this->override = $override;
    }

    public function setDefaults($gibbonDepartmentIDList = null, $course = null)
    {
        $this->gibbonDepartmentIDList = $gibbonDepartmentIDList;
        $this->course = $course;
    }

    public function importFromFile($zipFilePath) : bool
    {
        $zip = new ZipArchive();
        $zip->open($zipFilePath);

        $json = $zip->getFromName('data.json');
        $data = json_decode($json, true);

        if (empty($data['units'])) return false;

        // Upload all necessary files first
        $this->files = $this->uploadImportedFiles($data, $zipFilePath);

        // Import Units
        foreach ($data['units'] as $index => $unit) {
            $existingUnit = $this->unitGateway->selectBy(['name' => $unit['name']])->fetch();

            // Skip existing units if override is not enabled
            if (!$this->override && !empty($existingUnit)) {
                unset($data['units'][$index]);
                continue;
            }

            // Update certain values before importing
            $unit = $this->updateUnitDetails($unit);

            // Add or update the unit in the database
            if (!empty($existingUnit)) {
                $freeLearningUnitID = $existingUnit['freeLearningUnitID'];
                $this->unitGateway->update($freeLearningUnitID, $unit['unit']);
            } else {
                $freeLearningUnitID = $this->unitGateway->insert($unit['unit']);
            }

            // Add blocks and authors
            $this->addUnitBlocks($unit['blocks'], $freeLearningUnitID, $existingUnit);
            $this->addUnitAuthors($unit['authors'], $freeLearningUnitID, $existingUnit);
        }

        // Connect prerequisites after all units have been imported
        $this->connectUnitPrerequisites($data);

        $zip->close();

        unlink($zipFilePath);

        return true;
    }

    protected function uploadImportedFiles($data, $zipFilePath) {
        $this->files = [];

        foreach ($data['files'] as $filename) {
            $uploadsFolder = 'uploads/'.date('Y').'/'.date('m');
            $destinationPath = $this->session->get('absolutePath').'/'.$uploadsFolder.'/'.$filename;

            if (@copy('zip://'.$zipFilePath.'#files/'.$filename, $destinationPath)) {
                $this->files[$filename] = $this->session->get('absoluteURL').'/'.$uploadsFolder.'/'.$filename;
            }
        }

        return $this->files;
    }

    protected function updateUnitDetails($unit) {
        // Reset un-importable values
        $unit['unit']['gibbonPersonIDCreator'] = $this->session->get('gibbonPersonID');
        $unit['unit']['freeLearningUnitIDPrerequisiteList'] = '';

        // Apply default values
        if (!empty($this->gibbonDepartmentIDList)) $unit['unit']['gibbonDepartmentIDList'] = $this->gibbonDepartmentIDList;
        if (!empty($this->course)) $unit['unit']['course'] = $this->course;

        // Get the uploaded logo URL
        if (!empty($unit['unit']['logo']) && !empty($this->files[$unit['unit']['logo']])) {
            $unit['unit']['logo'] = $this->files[$unit['unit']['logo']] ?? '';
        }

        // Update unit outline to point to new file locations
        foreach ($this->files as $filename => $url) {
            $unit['unit']['outline'] = str_replace($filename, $url, $unit['unit']['outline']);
        }

        return $unit;
    }

    protected function addUnitBlocks($blocks, $freeLearningUnitID, $existingUnit)
    {
        foreach ($blocks as $block) {
            $block['freeLearningUnitID'] = $freeLearningUnitID;
            if (!empty($existingUnit)) {
                $existingBlock = $this->unitBlockGateway->selectBy([
                    'freeLearningUnitID' => $existingUnit['freeLearningUnitID'],
                    'title' => $block['title'],
                ])->fetch();
            }

            // Update uploaded files to point to their new file location
            foreach ($this->files as $filename => $url) {
                $block['contents'] = str_replace($filename, $url, $block['contents']);
            }

            if (!empty($existingBlock)) {
                $this->unitBlockGateway->update($existingBlock['freeLearningUnitBlockID'], $block);
            } else {
                $this->unitBlockGateway->insert($block);
            }
        }
    }

    protected function addUnitAuthors($authors, $freeLearningUnitID, $existingUnit)
    {
        foreach ($authors as $author) {
            $author['freeLearningUnitID'] = $freeLearningUnitID;
            $author['gibbonPersonID'] = null;

            if (!empty($existingUnit)) {
                $existingAuthor = $this->unitAuthorGateway->selectBy([
                    'freeLearningUnitID' => $existingUnit['freeLearningUnitID'],
                    'surname' => $author['surname'],
                    'preferredName' => $author['preferredName'],
                ])->fetch();
            }

            if (!empty($existingAuthor)) {
                $this->unitAuthorGateway->update($existingAuthor['freeLearningUnitAuthorID'], $author);
            } else {
                $this->unitAuthorGateway->insert($author);
            }
        }
    }

    protected function connectUnitPrerequisites($data)
    {
        foreach ($data['units'] as $unit) {
            if (empty($unit['prerequisites'])) continue;

            $existingUnit = $this->unitGateway->selectBy(['name' => $unit['name']])->fetch();

            $prerequisiteList = $this->unitGateway->selectPrerequisiteIDsByNames($unit['prerequisites'])->fetchAll(\PDO::FETCH_COLUMN);
            $this->unitGateway->update($existingUnit['freeLearningUnitID'], ['freeLearningUnitIDPrerequisiteList' => implode(',', $prerequisiteList)]);
        }
    }
}
