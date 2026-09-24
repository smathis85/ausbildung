<?php
declare(strict_types=1);
namespace Ausbildung;

/** Pass only a participant ID resolved by the server-side login/session. */
final class ParticipantOverview
{
    public function __construct(private Store $store) {}

    public function forAuthenticatedParticipant(int $participantId): ?array
    {
        $person=$this->store->one('SELECT id,first_name,last_name,department FROM participants WHERE id=? AND archived=0',[$participantId]);
        if(!$person)return null;
        $years=$this->store->rows('SELECT year,exam_date,exam_label FROM enrollments WHERE participant_id=? ORDER BY year DESC',[$participantId]);
        $annual=[];
        foreach($years as $year){
            $n=$this->store->total($participantId,(int)$year['year']);
            $annual[]=[
                'year'=>(int)$year['year'],
                'annual'=>$this->store->annual($participantId,(int)$year['year']),
                'total'=>$n,'missing'=>max(0,10-$n),'eligible'=>$n>=10,
                'exam_passed'=>(bool)($year['exam_date']||$year['exam_label']),
                'exam_date'=>$year['exam_date'],
            ];
        }
        $attendance=$this->store->rows('SELECT l.year,l.date,l.unit,l.title FROM attendance a JOIN lessons l ON l.id=a.lesson_id WHERE a.participant_id=? ORDER BY l.year DESC,l.date DESC,l.unit DESC',[$participantId]);
        return ['person'=>$person,'years'=>$annual,'attendance'=>$attendance];
    }
}
