<?php
// This file is part of local_downloadcenter for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Download center plugin
 *
 * @package       local_downloadcenter
 * @author        Simeon Naydenov (moniNaydenov@gmail.com)
 * @copyright     2015 Academic Moodle Cooperation {@link http://www.academic-moodle-cooperation.org}
 * @license       http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class local_downloadcenter_factory {
    private $course;
    private $user;
    private $sortedresources;
    private $filteredresources;
    private $availableresources = array('resource', 'folder', 'publication');
    private $jsnames = array();
    private $progress;

    public function __construct($course, $user) {
        $this->course = $course;
        $this->user = $user;
    }

    public function get_resources_for_user() {
        global $DB, $CFG;

        //only downloadable resources should be shown
        if (!empty($this->sortedresources)) {
            return $this->sortedresources;
        }

        $modinfo = get_fast_modinfo($this->course);
        $usesections = course_format_uses_sections($this->course->format);

        $sorted = array();
        if ($usesections) {
            $sections = $DB->get_records('course_sections', array('course' => $this->course->id), 'section');
            $sectionsformat = $DB->get_record('course_format_options', array('courseid' => $this->course->id, 'name' => 'numsections'));
            $max = count($sections);
            if ($sectionsformat) {
                $max = intval($sectionsformat->value);
            }
            $unnamedsections = array();
            $namedsections = array();
            foreach ($sections as $section) {
                if (intval($section->section) > $max) {
                    break;
                }
                if (!isset($sorted[$section->section]) && $section->visible) {
                    $sorted[$section->section] = new stdClass;
                    $title = trim(clean_filename(get_section_name($this->course, $section->section)));
                    $sorted[$section->section]->title = $title;
                    if (empty($title)) {
                        $unnamedsections[] = $section->section;
                    } else {
                        $namedsections[$title] = true;
                    }
                    $sorted[$section->section]->res = array(); //TODO: fix empty names here!!!
                }
            }
            foreach ($unnamedsections as $sectionid) {
                $title = 'Untitled';
                $i = 1;
                while (isset($namedsections[$title])) {
                    $title = 'Untitled ' . strval($i);
                    $i++;
                }
                $namedsections[$title] = true;
                $sorted[$sectionid]->title = $title;
            }
        } else {
            $sorted['default'] = new stdClass;//TODO: fix here if needed
            $sorted['default']->title = '0';
            $sorted['default']->res = array();
        }
        $cms = array();
        $resources = array();
        foreach ($modinfo->cms as $cm) {
            if (!in_array($cm->modname, $this->availableresources)) {
                continue;
            }
            if (!$cm->uservisible) {
                continue;
            }
            if (!$cm->has_view() && $cm->modname != 'folder') {
                // Exclude label and similar
                continue;
            }
            $cms[$cm->id] = $cm;
            $resources[$cm->modname][] = $cm->instance;
        }

        // preload instances
        foreach ($resources as $modname=>$instances) {
            $resources[$modname] = $DB->get_records_list($modname, 'id', $instances, 'id');
        }
        $available_sections = array_keys($sorted);
        $currentsection = '';
        foreach ($cms as $cm) {
            if (!isset($resources[$cm->modname][$cm->instance])) {
                continue;
            }
            $resource = $resources[$cm->modname][$cm->instance];

            if ($usesections) {
                if ($cm->sectionnum !== $currentsection) {
                    $currentsection = $cm->sectionnum;
                }
                if (!in_array($currentsection, $available_sections)) {
                    continue;
                }
            } else {
                $currentsection = 'default';
            }

            if (!isset($this->jsnames[$cm->modname])) {
                $this->jsnames[$cm->modname] = get_string('modulenameplural', 'mod_' . $cm->modname);
            }


            $icon = '<img src="'.$cm->get_icon_url().'" class="activityicon" alt="'.$cm->get_module_type_name().'" /> ';
            //TODO: $cm->visible..
            $res = new stdClass;
            $res->icon = $icon;
            $res->cmid = $cm->id;
            $res->name = $cm->get_formatted_name();
            $res->modname = $cm->modname;
            $res->instanceid = $cm->instance;
            $res->resource = $resource;
            $res->cm = $cm;
            $sorted[$currentsection]->res[] = $res;
        }



        $this->sortedresources = $sorted;
        return $sorted;

    }

    public function get_js_modnames() {
        return array($this->jsnames);
    }

    public function create_zip() {
        global $DB, $CFG, $USER;

        if (file_exists($CFG->dirroot . '/mod/publication/locallib.php')) {
            require_once($CFG->dirroot . '/mod/publication/locallib.php');
        } else {
            define('PUBLICATION_MODE_UPLOAD', 0);
            define('PUBLICATION_MODE_IMPORT', 1);
        }

        // Zip files and sent them to a user.
        $tempzip = tempnam($CFG->tempdir.'/', 'downloadcenter');
        $zipper = new zip_packer();
        $fs = get_file_storage();

        $filelist = array();
        $filteredresources = $this->filteredresources;


        if (empty($filteredresources)) {
           // return false;
        }

        //needed for mod_publication
        $ufields = user_picture::fields('u');
        $useridentityfields = $CFG->showuseridentity != '' ? 'u.'.str_replace(', ', ', u.', $CFG->showuseridentity) . ', ' : '';


        foreach ($filteredresources as $topicid => $info) {
            $basedir = clean_filename($info->title);
            $filelist[$basedir] = null;
            foreach ($info->res as $res) {
                $resdir = $basedir . '/' . clean_filename($res->name);
                $filelist[$resdir] = null;
                $context = context_module::instance($res->cm->id);
                if ($res->modname == 'resource') {
                    $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
                    $file = array_shift($files); //get only the first file - such are the requirements
                    $filename = $resdir . '/' . $file->get_filename();
                    $filelist[$filename] = $file;
                } else if ($res->modname == 'folder') {
                    $folder = $fs->get_area_tree($context->id, 'mod_folder', 'content', 0);
                    $this->add_folder_contents($filelist, $folder, $resdir);
                } else if ($res->modname == 'publication') {

                    $cm = $res->cm;

                    $conditions = array();
                    $conditions['publication'] = $res->instanceid;

                    $filesforzipping = array();
                    $filearea = 'attachment';

                    // Find out current groups mode.
                    $groupmode = groups_get_activity_groupmode($cm);
                    $currentgroup = groups_get_activity_group($cm, true);

                    // Get group name for filename.
                    $groupname = '';

                    // Get all ppl that are allowed to submit assignments.
                    list($esql, $params) = get_enrolled_sql($context, 'mod/publication:view', $currentgroup);

                    $showall = false;

                    if (has_capability('mod/publication:approve', $context) ||
                        has_capability('mod/publication:grantextension', $context)) {
                        $showall = true;
                    }

                    if ($showall) {
                        $sql = 'SELECT u.id FROM {user} u '.
                            'LEFT JOIN ('.$esql.') eu ON eu.id=u.id '.
                            'WHERE u.deleted = 0 AND eu.id=u.id';
                    } else {
                        $sql = 'SELECT u.id FROM {user} u '.
                            'LEFT JOIN ('.$esql.') eu ON eu.id=u.id '.
                            'LEFT JOIN {publication_file} files ON (u.id = files.userid) '.
                            'WHERE u.deleted = 0 AND eu.id=u.id '.
                            'AND files.publication = '. $res->instanceid . ' ';

                        if ($res->resource->mode == PUBLICATION_MODE_UPLOAD) {
                            // Mode upload.
                            if ($res->resource->obtainteacherapproval) {
                                // Need teacher approval.

                                $where = 'files.teacherapproval = 1';
                            } else {
                                // No need for teacher approval.
                                // Teacher only hasnt rejected.
                                $where = '(files.teacherapproval = 1 OR files.teacherapproval IS NULL)';
                            }
                        } else {
                            // Mode import.
                            if (!$res->resource->obtainstudentapproval) {
                                // No need to ask student and teacher has approved.
                                $where = 'files.teacherapproval = 1';
                            } else {
                                // Student and teacher have approved.
                                $where = 'files.teacherapproval = 1 AND files.studentapproval = 1';
                            }
                        }

                        $sql .= 'AND ' . $where . ' ';
                        $sql .= 'GROUP BY u.id';
                    }

                    $users = $DB->get_records_sql($sql, $params);
                    
                    if (!empty($users)) {
                        $users = array_keys($users);
                    }

                    // If groupmembersonly used, remove users who are not in any group.
                    if ($users and !empty($CFG->enablegroupmembersonly) and $cm->groupmembersonly) {
                        if ($groupingusers = groups_get_grouping_members($cm->groupingid, 'u.id', 'u.id')) {
                            $users = array_intersect($users, array_keys($groupingusers));
                        }
                    }

                    $userfields = get_all_user_name_fields();
                    $userfields['id'] = 'id';
                    $userfields['username'] = 'username';
                    $userfields = implode(', ', $userfields);

                    $viewfullnames = has_capability('moodle/site:viewfullnames', $context);

                    // Get all files from each user.
                    foreach ($users as $uploader) {
                        $auserid = $uploader;

                        $conditions['userid'] = $uploader;
                        $records = $DB->get_records('publication_file', $conditions);

                        // Get user firstname/lastname.
                        $auser = $DB->get_record('user', array('id' => $auserid), $userfields);

                        foreach ($records as $record) {

                            $haspermission = false;

                            if ($res->resource->mode == PUBLICATION_MODE_UPLOAD) {
                                // Mode upload.
                                if ($res->resource->obtainteacherapproval) {
                                    // Need teacher approval.
                                    if ($record->teacherapproval == 1) {
                                        // Teacher has approved.
                                        $haspermission = true;
                                    }
                                } else {
                                    // No need for teacher approval.
                                    if (is_null($record->teacherapproval) || $record->teacherapproval == 1) {
                                        // Teacher only hasnt rejected.
                                        $haspermission = true;
                                    }
                                }
                            } else {
                                // Mode import.
                                if (!$res->resource->obtainstudentapproval && $record->teacherapproval == 1) {
                                    // No need to ask student and teacher has approved.
                                    $haspermission = true;
                                } else if ($res->resource->obtainstudentapproval &&
                                    $record->teacherapproval == 1 &&
                                    $record->studentapproval == 1) {
                                    // Student and teacher have approved.
                                    $haspermission = true;
                                }
                            }

                            if (has_capability('mod/publication:approve', $context) || $haspermission) {
                                // Is teacher or file is public.

                                $file = $fs->get_file_by_id($record->fileid);

                                // Get files new name.
                                $fileext = strstr($file->get_filename(), '.');
                                $fileoriginal = str_replace($fileext, '', $file->get_filename());
                                $fileforzipname = clean_filename(($viewfullnames ? fullname($auser) : '') .
                                    '_' . $fileoriginal.'_'.$auserid.$fileext);
                                $fileforzipname = $resdir . '/' . $fileforzipname;
                                // Save file name to array for zipping.
                                $filelist[$fileforzipname] = $file;
                            }
                        }
                    } // End of foreach.


                }
            }
        }

        if ($zipper->archive_to_pathname($filelist, $tempzip)) {
            $filename = sprintf('%s_%s.zip', $this->course->shortname, userdate(time(), '%Y%m%d_%H%M'));
            //send_temp_file($tempzip, clean_filename($filename));
            return $this->add_file_to_session($tempzip, clean_filename($filename));

        } else {
            debugging("Problems with archiving the files.", DEBUG_DEVELOPER);
            die;
        }
    }

    private function add_file_to_session($tempfilename, $realfilename) {
        global $SESSION;
        if (!isset($SESSION->local_downloadcenter_filelist)) {
            $SESSION->local_downloadcenter_filelist = array();
        }
        $hash = sha1($tempfilename . $realfilename);
        $info = new stdClass;
        $info->tempfilename = $tempfilename;
        $info->realfilename = $realfilename;
        $SESSION->local_downloadcenter_filelist[$hash] = $info;
        return $hash;
    }

    public function get_file_from_session($hash) {
        global $SESSION;
        if (isset($SESSION->local_downloadcenter_filelist)) {
            if (isset($SESSION->local_downloadcenter_filelist[$hash])) {
                $info = $SESSION->local_downloadcenter_filelist[$hash];
                unset($SESSION->local_downloadcenter_filelist[$hash]);
                send_temp_file($info->tempfilename, $info->realfilename);
            }
        }
        debugging("Problems with getting the files from server.", DEBUG_DEVELOPER);
        die;
    }

    private function add_folder_contents(&$filelist, $folder, $path) {
        if (!empty($folder['subdirs'])) {
            foreach ($folder['subdirs'] as $foldername => $subfolder) {
                $this->add_folder_contents($filelist, $subfolder, $path . '/' . $foldername);
            }
        }
        foreach ($folder['files'] as $filename => $file) {
            $filelist[$path . '/' . $filename] = $file;
        }
    }

    public function parse_form_data($data) {
        $data = (array)$data;
        $filtered = array();


        $sortedresources = $this->get_resources_for_user();
        foreach ($sortedresources as $sectionid => $info) {
            if (!isset($data['item_topic_' . $sectionid])) {
                continue;
            }
            $filtered[$sectionid] = new stdClass;
            $filtered[$sectionid]->title = $info->title;
            $filtered[$sectionid]->res = array();
            foreach ($info->res as $res) {
                $name = 'item_' . $res->modname . '_' . $res->instanceid;
                if (!isset($data[$name])) {
                    continue;
                }
                $filtered[$sectionid]->res[] = $res;
            }
        }

        $this->filteredresources = $filtered;
    }

}