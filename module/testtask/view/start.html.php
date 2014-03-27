<?php
/**
 * The start file of testtask module of ZenTaoPMS.
 *
 * @copyright   Copyright 2009-2013 青岛易软天创网络科技有限公司 (QingDao Nature Easy Soft Network Technology Co,LTD www.cnezsoft.com)
 * @license     LGPL (http://www.gnu.org/licenses/lgpl.html)
 * @author      Chunsheng Wang<wwccss@gmail.com>
 * @package     testtask
 * @version     $Id: start.html.php 935 2013-01-16 07:49:24Z wwccss@gmail.com $
 * @link        http://www.zentao.net
 */
?>
<?php include '../../common/view/header.html.php';?>
<?php include '../../common/view/kindeditor.html.php';?>
<?php include '../../common/view/datepicker.html.php';?>
<form class='form-condensed' method='post' target='hiddenwin'>
  <table class='table table-form'>
    <caption><?php echo $testtask->name;?></caption>
    <tr>
      <td><?php echo $lang->comment;?></td>
      <td><?php echo html::textarea('comment', '', "rows='6' class='form-control'");?></td>
    </tr>
    <tr>
      <td colspan='2' class='text-center'><?php echo html::submitButton() . html::linkButton($lang->goback, $this->session->taskList); ?></td>
    </tr>
  </table>
  <?php include '../../common/view/action.html.php';?>
</form>
<?php include '../../common/view/footer.html.php';?>
