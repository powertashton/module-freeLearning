<?php
$I = new AcceptanceTester($scenario);
$I->wantTo('Change and check settings');
$I->loginAsAdmin();


$I->amOnModulePage('Free Learning', 'units_manage.php');
$I->seeBreadcrumb('Manage Units');


$I->click('Add');
$I->seeBreadcrumb('Add Unit');


$I->fillField('name', 'Test Unit');
$I->selectFromDropdown('difficulty', 2);
$I->fillField('blurb', 'Test Unit Blurb');
$I->click('Submit');
$I->seeSuccessMessage();
$editID = $I->grabValueFromURL('editID');
