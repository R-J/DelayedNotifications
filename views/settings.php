<?php defined('APPLICATION') or die;
$this->Form->showErrors();
 ?>
<h1><?= $this->data('Title') ?></h1>
<div class="padded">
    <?= $this->data('Description') ?>
</div>
<div class="padded alert  ">
    <?= sprintf($this->data('UrlDescription'), $this->data('SecretUrl')) ?>
</div>
<?= $this->Form->open(), $this->Form->errors() ?>
<ul>
    <li class="form-group">
        <div class="label-wrap">
            <?= $this->Form->label($this->data('PeriodLabel'), 'Period') ?>
        </div>
        <div class="input-wrap">
            <?= $this->Form->dropDown('Period', $this->data('Periodsarray'), ['value' => $this->data('Period')]) ?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label($this->data('ExtractLabel'), 'Extract') ?>
            <div class="info">
                <?= $this->data('ExtractDescription') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textbox('Extract', ['type' => 'number', 'min' => '0', 'max' => '300', 'step' => '30','value' => $this->data('Extract')]) ?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label($this->data('GetimageLabel'), 'Getimage') ?>
            <div class="info">
                <?= $this->data('GetimageDescription') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->checkbox('Getimage', ['value' => $this->data('Getimage')]) ?>
        </div>
    </li>
</ul>
<?= $this->Form->close('Save') ?>
