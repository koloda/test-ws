<?php

class DeepCopy
{
    private $conn;
    private $taskId;
    private $total;
    private $copyProcessId;

    private $tasksPerTransaction = 10;
    private $currentOffset = 0;

    private $newAndSourceIds = [];

    private $emulateException = true;

    public function __construct(int $taskId, ?int $copyProcessId = null)
    {
        $this->taskId = $taskId;
        $this->conn = new mysqli("localhost", "root", "kolo90da", "testdb");
        $this->conn->autocommit(false);

        if ($copyProcessId) {
            $this->copyProcessId = $copyProcessId;
            $sql = "select * from copy_process where id = {$copyProcessId}";
            $result = $this->conn->query($sql);
            $row = $result->fetch_assoc();

            if (!$row) {
                throw new Exception('Copy process not found');
            }

            $this->total = $row['total'];
            $this->currentOffset = $row['processed'];
            $this->newAndSourceIds = json_decode($row['new_old_ids'], true);
            $this->emulateException = false;
        } else {
            $this->calculateTotal();
            $this->createCopyProcess();
        }

        $this->copyTaskTree();
    }

    public static function continueLastFailedCopyProcess()
    {
        $conn = new mysqli("localhost", "root", "kolo90da", "testdb");
        $conn->autocommit(false);

        $sql = "select * from copy_process where status = 'in progress' order by id desc limit 1";
        $result = $conn->query($sql);
        $row = $result->fetch_assoc();

        new DeepCopy($row['task_id'], $row['id']);
    }

    private function calculateTotal()
    {
        //use mysql with recursive here
        $sql = "with recursive cte as (
                select id, parent_id, name, is_public, visible_for_users from tasks where id = {$this->taskId}
                union all
                select t.id, t.parent_id, t.name, t.is_public, t.visible_for_users from tasks t
                inner join cte on cte.id = t.parent_id
            )
            select count(id) from cte";

        $result = $this->conn->query($sql);

        if ($result->num_rows > 0) {
            $this->total = $result->fetch_assoc()['count(id)'];
        } else {
            $this->total = 0;
        }
    }

    private function createCopyProcess()
    {
        $sql = "insert into copy_process (task_id, total, processed, status) values ({$this->taskId}, {$this->total}, 0, 'in progress')";
        $this->conn->query($sql);
        $this->copyProcessId = $this->conn->insert_id;
    }

    private function copyTaskTree()
    {
        //use transactions here per 100 tasks
        while ($this->currentOffset < $this->total) {
            $this->conn->begin_transaction();

            $this->copyFlatTasksTreePart();
            $this->currentOffset += $this->tasksPerTransaction;
            $this->updateCopyProcess();

            $this->conn->commit();

            //emulate exception
            if ($this->currentOffset >= 20 && $this->emulateException) {
                throw new Exception('test');
            }
        }

        $sql = "update copy_process set status = 'done' where id = {$this->copyProcessId}";
        $this->conn->query($sql);
        $this->conn->close();
    }

    private function copyFlatTasksTreePart()
    {
        //use mysql with recursive here
        $sql = "with recursive cte as (
                select id, parent_id, name, is_public, visible_for_users from tasks where id = {$this->taskId}
                union all
                select t.id, t.parent_id, t.name, t.is_public, t.visible_for_users from tasks t
                inner join cte on cte.id = t.parent_id
            )
            select * from cte limit {$this->currentOffset}, {$this->tasksPerTransaction}";

        $result = $this->conn->query($sql);

        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $sql = "INSERT INTO tasks (name, parent_id, is_public, visible_for_users) VALUES ";
                $newParentId = $this->getNewParentId($row['parent_id']) ?: 'null';

                $sql .= "('{$row['name']} (copy)', {$newParentId}, {$row['is_public']}, '{$row['visible_for_users']}')";

                $this->conn->query($sql);

                $this->newAndSourceIds[] = [
                    'new' => $this->conn->insert_id,
                    'source' => $row['id']
                ];
            }
        }
    }

    private function updateCopyProcess()
    {
        $sql = "update copy_process set processed = {$this->currentOffset},
            new_old_ids = '" . json_encode($this->newAndSourceIds) . "'
         where id = {$this->copyProcessId}";
        $this->conn->query($sql);
    }

    private function getNewParentId(?int $parentId)
    {
        if (!$parentId) {
            return null;
        }

        foreach ($this->newAndSourceIds as $row) {
            if ($row['source'] == $parentId) {
                return $row['new'];
            }
        }

        return null;
    }
}

// $dc = new DeepCopy(1);

DeepCopy::continueLastFailedCopyProcess();