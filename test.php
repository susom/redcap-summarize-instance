<?php
namespace Stanford\SelectRepeatInstance;

include_once (APP_PATH_DOCROOT . "ProjectGeneral/header.php");

global $project_id;

// $demo = new FormHelper($project_id, 'demographics', $module);
// // $demo->loadData(null, 224);
// $demo->loadData(1, 224);
// echo "<pre>" . print_r($demo,true) . "</pre>";


$meds = new FormHelper($project_id, 'medications', $module);


$meds->loadData(1,224);

echo "<pre>" . print_r($meds->getFirstInstanceId(1,224),true) . "</pre>";

echo "<pre>" . print_r($meds->getLastInstanceId(1,224),true) . "</pre>";

echo "<pre>" . print_r($meds->getNextInstanceId(1,224),true) . "</pre>";


$i2 = $meds->getData(1,2, 224);
echo "<pre>" . print_r($i2,true) . "</pre>";

// $result = $meds->saveInstance(1,$i2, 5, 224);
// echo "<pre>" . print_r($result,true) . "</pre>";


echo "<pre>" . print_r($meds->getData(1,10, 224),true) . "</pre>";


echo "<pre>" . print_r($meds->data,true) . "</pre>";

