<?php
/** Reference data shared by the forms, the API and the admin dashboard. */
declare(strict_types=1);

const COUNTIES = ['Alamance', 'Guilford', 'Orange', 'Durham', 'Wake', 'Chatham', 'Randolph', 'Caswell',
                  'Person', 'Forsyth', 'Granville', 'Vance', 'Johnston', 'Franklin', 'Other'];

const SERVICES = [
    'Alzheimer’s & Dementia Home Care', 'Post-Surgical Care', 'Assisted Living Home Services',
    'Private Duty Nursing', 'Chronic Illness Management', 'Disability Care', 'Adult Care Home',
    'Non-Medical Home Care', 'Pediatric Care',
];

const POSITIONS = ['Caregiver / Direct Care Worker', 'Certified Nursing Assistant (CNA)',
                   'Licensed Practical Nurse (LPN)', 'Registered Nurse (RN)', 'Office / Administrative', 'Other'];

const AVAILABILITY = ['Full-time', 'Part-time', 'Weekends', 'Overnights', 'Flexible'];

/** Workflow statuses: key => label. The first one is the default for new records. */
const INQUIRY_STATUSES = [
    'new' => 'New', 'contacted' => 'Contacted', 'assessment' => 'Assessment scheduled',
    'active' => 'Active client', 'closed' => 'Closed',
];
const APPLICATION_STATUSES = [
    'new' => 'New', 'reviewing' => 'Reviewing', 'interview' => 'Interview',
    'hired' => 'Hired', 'declined' => 'Not selected',
];

const ENTITY_TYPES = [
    'inquiry'     => ['table' => 'inquiries',    'label' => 'Care request',    'plural' => 'Care requests', 'statuses' => INQUIRY_STATUSES],
    'application' => ['table' => 'applications', 'label' => 'Job application', 'plural' => 'Job applications', 'statuses' => APPLICATION_STATUSES],
];
