<?php defined('APPLICATION') or die;

$photo = $this->data('Photo', false);
$image = $this->data('Image', false);
$commentText = $this->data('CommentText', false);
$extractText = $this->data('ExtractText', false);
$prefix = $this->data('Prefix', false);

?>
<span style="border:3px none #0074d966;border-bottom-style:solid;display:block;width:98%;white-space:break-spaces;padding: 3px 0px;line-height: 1;">
<table width="98%" cellspacing="0" cellpadding="0" border="0" margin-bottom: 10px;>
    <colgroup>
        <col style="vertical-align: top;" />
        <col />
    </colgroup>
    <tr>
        <?php if ($photo): ?>
        <td width="26px" valign="top" align="right">' .
            <span style="border-radius: 4px;padding: 0px 5px;vertical-align: top;display: table-cell;">
                <span style="display:inline-block;margin:4px;vertical-align: middle;">
                    <img src="<?= $photo ?>" style="width:24px;height:24px;border-radius:4px;"></img>',
                </span>
            </span>
        </td>
        <?php endif ?>
        <td>
            <span style="vertical-align: middle;">
                <?= $this->data('Headline') ?>
            </span>
            <br>
            <?= $this->data('On') ?>
        </td>
    </tr>
</table>
<span style="display:block;width:98%;white-space:break-spaces;padding-top: 6px;line-height: 1;">
<table width="98%" cellspacing="0" cellpadding="0" border="0">
    <colgroup>
        <col style="vertical-align: top;" />
        <col />
    </colgroup>
    <tr>
        <?php if($image): ?>
        <td width="78px" valign="top" align="right">
            <span style="border-radius: 4px;padding: 0px 5px;vertical-align: top;display: table-cell;">
                <img width="120px" style="display:block; border-radius:6px; border:solid 1px rgba(0,0,0,.08);vertical-align: top;" src="<?= $image ?>" />
            </span>
        </td>
        <?php else: ?>
        <td width="18px" valign="top" align="right">
            <span style="border-radius:4px;padding:0px 5px;display: table-cell;" />
        </td>
        <?php endif ?>
        <?php if ($extractText): ?>
        <td style="line-height:1.2;">
            <?php if ($prefix): ?>
            <span style="background:darkcyan;color:white;padding:0px 4px;"><?= $prefix ?></span>
            <?php endif ?>
            <?php if ($commentText): ?>
            <span style="background:white;color:#306fa6;padding:0px 4px;text-shadow:1px 0px 0px#0561a6;'"> <?= $commentText ?></span>
            <?php endif ?>
            <br />
            <?= $extractText ?>
        </td>
        <?php else: ?>
        <td><?= $this->data('Story', '') ?></td>
        <?php endif ?>
    </tr>
</table>
</span>
</span>
