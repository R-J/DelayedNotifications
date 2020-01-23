<?php defined('APPLICATION') or die;
$this->Form->showErrors();
 ?>
<h1><?= $this->data('Title') ?></h1>
<div class="padded">
    <?= $this->data('Description') ?>
</div>
<div class="padded alert  " style="border:1px solid blue;-left:2px;">
    <div  style="margin-left:10px;">
        <?= sprintf($this->data('UrlDescription'), $this->data('SecretUrl')) ?>
    </div>
    <div  style="margin-left:10px;">
        <?= $this->data('ParameterlDescription') ?>
    </div>
</div>
<?= $this->Form->open(), $this->Form->errors() ?>
<ul>
    <li class="form-group">
        <div class="label-wrap">
            <?= $this->Form->label($this->data('PeriodLabel'), 'Period') ?>
            <div class="info" style="margin-left:10px;">
                <?= $this->data('PeriodDescription').'<br>' ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->dropDown('Period', $this->data('Periodsarray'), ['value' => $this->data('Period')]) ?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label($this->data('MaxemailLabel'), 'Maxemail') ?>
            <div class="info" style="margin-left:10px;">
                <?= $this->data('MaxemailDescription').'<br>' ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textbox('Maxemail', [ 'min' => '1', 'max' => '300', 'step' => '1','value' => $this->data('Maxemail')]) ?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label($this->data('ExtractLabel'), 'Extract') ?>
            <div class="info" style="margin-left:10px;">
                <?= $this->data('ExtractDescription').'<br>' ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textbox('Extract', ['type' => 'number', 'min' => '0', 'max' => '300', 'step' => '30','value' => $this->data('Extract')]) ?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label($this->data('GetimageLabel'), 'Getimage') ?>
            <div class="info" style="margin-left:10px;">
                <?= $this->data('GetimageDescription') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->CheckBox('Getimage', '') ?>
        </div>
    </li>
</ul>
<?= $this->Form->close('Save') ?>
