<?php defined('APPLICATION') or die;
 ?>
<h1><?= $this->data('Title') ?></h1>
<div class="padded">
    <?= $this->data('Description') ?>
</div>
<div class="padded alert alert-info">
    <?= sprintf($this->data('UrlDescription'), $this->data('SecretUrl')) ?>
</div>
<?= $this->Form->open(), $this->Form->errors() ?>
<ul>
    <li class="form-group">
        <div class="label-wrap">
            <?= $this->Form->label('Consolidation Period', 'Period') ?>
            <div class="info">
                <?= $this->data('PeriodDescription') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textbox('Period', ['type' => 'number', 'value' => $this->data('Period')]) ?>
            <?= $this->Form->inlineError('Period')?>
        </div>
        <div class="label-wrap">
            <?= $this->Form->label('Extract snippet', 'Extract') ?>
            <div class="info">
                <?= $this->data('ExtractDescription') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textbox('Extract', ['type' => 'number', 'value' => $this->data('Extract')]) ?>
            <?= $this->Form->inlineError('Extract')?>
        </div>
    </li>
</ul>
<?= $this->Form->close('Save') ?>
