<?php

final class TaskTableDataProvider {

  private $project;
  private $viewer;
  private $request;
  private $tasks;
  private $taskpoints;
  private $query;
  private $rows;
  private $order;
  private $reverse;


  public function setProject ($project) {
    $this->project = $project;
    return $this;
  }

  public function setViewer ($viewer) {
    $this->viewer = $viewer;
    return $this;
  }

  public function setRequest ($request) {
    $this->request = $request;
    return $this;
  }

  public function setTasks ($tasks) {
    $this->tasks = $tasks;
    return $this;
  }

  public function setTaskPoints ($taskpoints) {
    $this->taskpoints = $taskpoints;
    return $this;
  }

  public function setQuery ($query) {
    $this->query = $query;
    return $this;
  }

  public function getRows () {
    return $this->rows;
  }

  public function getRequest () {
    return $this->request;
  }

  public function getOrder () {
    return $this->order;
  }

  public function getReverse () {
    return $this->reverse;
  }

  public function execute() {
    return $this->buildTaskTableData();
  }

  private function buildTaskTableData() {
    $order = $this->request->getStr('order', 'name');
    list($this->order, $this->reverse) = AphrontTableView::parseSort($order);
    $edges = $this->query->getEdges($this->tasks);
    $map = $this->buildTaskMap($edges, $this->tasks);
    $sprintpoints = id(new SprintPoints())
        ->setTaskPoints($this->taskpoints);

    $handles = $this->getHandles();

    $output = array();
    $rows = array();
    foreach ($this->tasks as $task) {
      $blocked = false;
      if (isset($map[$task->getPHID()]['child'])) {
        foreach (($map[$task->getPHID()]['child']) as $phid) {
          $ctask = $this->getTaskforPHID($phid);
          foreach ($ctask as $child) {
            if (ManiphestTaskStatus::isOpenStatus($child->getStatus())) {
              $blocked = true;
              break;
            }
          }
        }
      }

      $ptasks = array();
      $phid = null;
      $blocker = false;
      if (isset($map[$task->getPHID()]['parent'])) {
        $blocker = true;
        foreach (($map[$task->getPHID()]['parent']) as $phid) {
          $ptask = $this->getTaskforPHID($phid);
          $ptasks = array_merge($ptasks, $ptask);
        }
      }

      $points = $sprintpoints->getTaskPoints($task->getPHID());

      $row = $this->addTaskToTree($output, $blocked, $ptasks, $blocker,
          $task, $handles, $points);
      list ($task, $cdate, , $udate, , $owner_link, $numpriority, , $points,
          $status) = $row[0];
      $row['sort'] = $this->setSortOrder($row, $order, $task, $cdate, $udate,
          $owner_link, $numpriority, $points, $status);
      $rows[] = $row;
    }
    $rows = isort($rows, 'sort');

    foreach ($rows as $k => $row) {
      unset($rows[$k]['sort']);
    }

    if ($this->reverse) {
      $rows = array_reverse($rows);
    }

    $a = array();
    $this->rows = array_map(function($a) { return $a['0']; }, $rows);
    return $this;
  }

  private function setSortOrder ($row, $order, $task, $cdate, $udate,
                                 $owner_link, $numpriority, $points, $status) {
    switch ($order) {
      case 'Task':
        $row['sort'] = $task;
        break;
      case 'Date Created':
        $row['sort'] = $cdate;
        break;
      case 'Last Update':
        $row['sort'] = $udate;
        break;
      case 'Assigned to':
        $row['sort'] = $owner_link;
        break;
      case 'Priority':
        $row['sort'] = $numpriority;
        break;
      case 'Points':
        $row['sort'] = $points;
        break;
      case 'Status':
        $row['sort'] = $status;
        break;
      default:
        $row['sort'] = -$numpriority;
        break;
    }
    return $row['sort'];
  }

  private function buildTaskMap ($edges, $tasks) {
    $map = array();
    foreach ($tasks as $task) {
      $phid = $task->getPHID();
      if ($parents =
          $edges[$phid][ ManiphestTaskDependedOnByTaskEdgeType::EDGECONST]) {
        foreach ($parents as $parent) {
          if (isset($tasks[$parent['dst']])) {
            $map[$phid]['parent'][] = $parent['dst'];
          }
        }
      } else if ($children =
          $edges[$phid][ManiphestTaskDependsOnTaskEdgeType::EDGECONST]) {
        foreach ($children as $child) {
          if (isset($tasks[$child['dst']])) {
            $map[$phid]['child'][] = $child['dst'];
          }
        }
      }
    }
    return $map;
  }

  private function getHandles() {
    $handle_phids = array();
    foreach ($this->tasks as $task) {
      $phid = $task->getOwnerPHID();
      $handle_phids[$phid] = $phid;
    }
    $handles = $this->query->getViewerHandles($this->request, $handle_phids);
    return $handles;
  }

  private function setOwnerLink($handles, $task) {
    $phid = $task->getOwnerPHID();
    $owner = $handles[$phid];

    if ($owner instanceof PhabricatorObjectHandle) {
      $owner_link = $phid ? $owner->renderLink() : 'none assigned';
    } else {
      $owner_link = 'none assigned';
    }
    return $owner_link;
  }

  private function getTaskCreatedDate($task) {
    $date_created = $task->getDateCreated();
    return $date_created;
  }

  private function getTaskModifiedDate($task) {
    $last_updated = $task->getDateModified();
    return $last_updated;
  }

  private function getPriorityName($task) {
    $priority_name = new ManiphestTaskPriority();
    return $priority_name->getTaskPriorityName($task->getPriority());
  }

  private function getPriority($task) {
    return $task->getPriority();
  }

  private function addTaskToTree($output, $blocked, $ptasks, $blocker,
                                 $task, $handles, $points) {

    $cdate = $this->getTaskCreatedDate($task);
    $date_created = phabricator_datetime($cdate, $this->viewer);
    $udate = $this->getTaskModifiedDate($task);
    $last_updated = phabricator_datetime($udate, $this->viewer);
    $status = $task->getStatus();

    $owner_link = $this->setOwnerLink($handles, $task);
    $priority = $this->getPriority($task);
    $priority_name = $this->getPriorityName($task);
    $is_open = ManiphestTaskStatus::isOpenStatus($task->getStatus());

    if ($blocker === true && $is_open === true) {
      $blockericon = $this->getIconforBlocker($ptasks);
    } else {
      $blockericon = '';
    }

    if ($blocked === true && $is_open === true) {
      $blockedicon = $this->getIconforBlocked();
    } else {
      $blockedicon = '';
    }

    $output[] = array(
        phutil_safe_html(phutil_tag(
            'a',
            array(
                'href' => '/'.$task->getMonogram(),
                'class' => $status !== 'open'
                    ? 'phui-tag-core-closed'
                    : '',
            ),
            array ($this->buildTaskLink($task), $blockericon,
                $blockedicon,))),
        $cdate,
        $date_created,
        $udate,
        $last_updated,
        $owner_link,
        $priority,
        $priority_name,
        $points,
        $status,
    );

    return $output;
  }

  private function getIconforBlocker($ptasks) {
    $linktasks = array();
    $links = null;
    foreach ($ptasks as $task) {
      $linktasks[] = $this->buildTaskLink($task);
      $links = implode('|  ', $linktasks);
    }

    $sigil = 'has-tooltip';
    $meta  = array(
        'tip' => pht('Blocks: '.$links),
        'size' => 500,
        'align' => 'E',);
    $image = id(new PHUIIconView())
        ->addSigil($sigil)
        ->setMetadata($meta)
        ->setSpriteSheet(PHUIIconView::SPRITE_PROJECTS)
        ->setIconFont('fa-wrench', 'green')
        ->setText('Blocker');
    return $image;
  }

  private function getIconforBlocked() {
    $image = id(new PHUIIconView())
        ->setSpriteSheet(PHUIIconView::SPRITE_PROJECTS)
        ->setIconFont('fa-lock', 'red')
        ->setText('Blocked');
    return $image;
  }

  private function buildTaskLink($task) {
    $linktext = $task->getMonogram().': '.$task->getTitle().'  ';
    return $linktext;
  }

  private function getTaskforPHID($phid) {
    $task = id(new ManiphestTaskQuery())
        ->setViewer($this->viewer)
        ->withPHIDs(array($phid))
        ->execute();
    return $task;
  }
}
