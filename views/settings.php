<?php defined('APPLICATION') or die;

decho($this->data('Period'));
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
                <?= $this->data('Description') ?>
            </div>
        </div>
        <div class="input-wrap">
            <?= $this->Form->textBox('Period', ['type' => 'number', 'value' => $this->data('Period')]) ?>
        </div>
    </li>
</ul>
<?= $this->Form->close('Save') ?>
