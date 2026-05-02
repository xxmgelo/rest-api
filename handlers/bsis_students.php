<?php

/**
 * Handler: BSIS Students
 *
 * GET    /api/bsis-students          — list all BSIS students      (admin only)
 * GET    /api/bsis-students/{id}    — get single student       (admin only)
 * POST   /api/bsis-students         — create student         (admin only)
 * PATCH  /api/bsis-students/{id}    — update student fields (admin only)
 * DELETE /api/bsis-students/{id}    — delete student         (admin only)
 */

function handleBsisStudents(PDO $pdo, string $method, ?int $id, array $body): void
{
    requireAdmin($pdo);

    switch ($method) {

        /* ── GET ── */
        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare(
                    'SELECT s.id, s.student_id, s.name, s.program, s.year_level, s.gmail, s.school_year_id, s.semester, sy.label AS school_year_label
                     FROM bsis_students s
                     LEFT JOIN school_years sy ON sy.id = s.school_year_id
                     WHERE s.id = :id'
                );
                $stmt->execute([':id' => $id]);
                $student = $stmt->fetch();
                $student
                    ? respond(200, $student)
                    : respond(404, null, 'Student not found');
            } else {
                $search  = $_GET['search']   ?? null;
                $year    = $_GET['year']     ?? null;
                $schoolYearId = isset($_GET['school_year_id']) ? (int) $_GET['school_year_id'] : 0;
                $semester = trim((string) ($_GET['semester'] ?? ''));

                $sql    = 'SELECT s.id, s.student_id, s.name, s.program, s.year_level, s.gmail, s.school_year_id, s.semester, sy.label AS school_year_label
                           FROM bsis_students s
                           LEFT JOIN school_years sy ON sy.id = s.school_year_id
                           WHERE 1=1';
                $params = [];

                if ($search) {
                    $sql           .= ' AND (s.name LIKE :search OR s.student_id LIKE :search)';
                    $params[':search'] = '%' . $search . '%';
                }
                if ($year) {
                    $sql         .= ' AND s.year_level = :year';
                    $params[':year'] = $year;
                }
                if ($schoolYearId > 0) {
                    $sql .= ' AND s.school_year_id = :school_year_id';
                    $params[':school_year_id'] = $schoolYearId;
                }
                if ($semester !== '') {
                    $sql .= ' AND s.semester = :semester';
                    $params[':semester'] = $semester;
                }
                $sql .= ' ORDER BY s.name';

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                respond(200, $stmt->fetchAll());
            }
            break;

        /* ── POST ── */
        case 'POST':
            $errors = [];
            if (empty($body['student_id'])) $errors[] = 'student_id is required';
            if (empty($body['name']))     $errors[] = 'name is required';
            if (empty($body['school_year_id'])) $errors[] = 'school_year_id is required';
            if (empty($body['semester'])) $errors[] = 'semester is required';
            if ($errors) respond(400, null, implode(', ', $errors));

            $stmt = $pdo->prepare(
                'INSERT INTO bsis_students (student_id, name, program, year_level, gmail, school_year_id, semester)
                 VALUES (:student_id, :name, :program, :year_level, :gmail, :school_year_id, :semester)'
            );

            try {
                $stmt->execute([
                    ':student_id' => trim($body['student_id']),
                    ':name'     => trim($body['name']),
                    ':program'  => $body['program'] ?? 'BSIS',
                    ':year_level' => $body['year_level'] ?? '',
                    ':gmail'    => $body['gmail'] ?? '',
                    ':school_year_id' => (int) ($body['school_year_id'] ?? 0),
                    ':semester' => trim((string) ($body['semester'] ?? '')),
                ]);
                $newId = (int) $pdo->lastInsertId();
                $fetchStmt = $pdo->prepare(
                    'SELECT s.id, s.student_id, s.name, s.program, s.year_level, s.gmail, s.school_year_id, s.semester, sy.label AS school_year_label
                     FROM bsis_students s
                     LEFT JOIN school_years sy ON sy.id = s.school_year_id
                     WHERE s.id = :id'
                );
                $fetchStmt->execute([':id' => $newId]);
                respond(201, $fetchStmt->fetch() ?: ['id' => $newId]);
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    respond(409, null, 'Student ID already exists');
                }
                throw $e;
            }
            break;

        /* ── PATCH ── */
        case 'PATCH':
            if (!$id) respond(400, null, 'Student ID required in URL');

            $allowed = [
                'student_id', 'name', 'program', 'year_level', 'gmail',
                'downpayment_date', 'prelim_date', 'midterm_date', 'prefinal_date', 'final_date', 'total_balance_date',
                'downpayment_paid_amount', 'prelim_paid_amount', 'midterm_paid_amount', 'prefinal_paid_amount', 'final_paid_amount', 'total_balance_paid_amount'
            ];
            $fields  = [];
            $params  = [':id' => $id];

            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $fields[]            = "{$field} = :{$field}";
                    $params[":{$field}"] = $body[$field];
                }
            }

            if (empty($fields)) respond(400, null, 'No updatable fields provided');

            $sql = 'UPDATE bsis_students SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $pdo->prepare($sql)->execute($params);
            respond(200, ['updated' => true]);
            break;

        /* ── DELETE ── */
        case 'DELETE':
            if (!$id) respond(400, null, 'Student ID required in URL');

            $stmt = $pdo->prepare('DELETE FROM bsis_students WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $stmt->rowCount()
                ? respond(200, ['deleted' => true])
                : respond(404, null, 'Student not found');
            break;

        default:
            respond(405, null, 'Method not allowed');
    }
}
