<?php

namespace App\Http\Controllers;

use App\Department;
use App\Myclass;
use App\Section;
use App\StudentInfo;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Hash;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Requests\User\CreateAdminRequest;
use App\Http\Requests\User\CreateParentRequest;
use App\Http\Requests\User\CreateTeacherRequest;
use App\Http\Requests\User\ChangePasswordRequest;
use App\Http\Requests\User\ImpersonateUserRequest;
use App\Http\Requests\User\CreateLibrarianRequest;
use App\Http\Requests\User\CreateAccountantRequest;
use Mavinoo\LaravelBatch\Batch;
use App\Events\UserRegistered;
use App\Events\StudentInfoUpdateRequested;
use Illuminate\Support\Facades\Log;
use App\Services\User\UserService;
// use App\Http\Controllers\UserController;
/**
 * Class UserController
 * @package App\Http\Controllers
 */
class UserController extends Controller
{
    protected $userService;
    protected $user;

    public function __construct(UserService $userService, User $user){
        $this->userService = $userService;
        $this->user = $user;
    }
    /**
     * Display a listing of the resource.
     *
     * @param $school_code
     * @param $student_code
     * @param $teacher_code
     * @return \Illuminate\Http\Response
     */
    public function index($school_code, $student_code, $teacher_code){
        session()->forget('section-attendance');
        
        if($this->userService->isListOfStudents($school_code, $student_code))
            return $this->userService->indexView('list.student-list', $this->userService->getStudents());
        else if($this->userService->isListOfTeachers($school_code, $teacher_code))
            return $this->userService->indexView('list.teacher-list',$this->userService->getTeachers());
        else
            return view('home');
    }

    /**
     * @param $school_code
     * @param $role
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function indexOther($school_code, $role){
        if($this->userService->isAccountant($role))
            return $this->userService->indexOtherView('accounts.accountant-list', $this->userService->getAccountants());
        else if($this->userService->isLibrarian($role))
            return $this->userService->indexOtherView('library.librarian-list', $this->userService->getLibrarians());
        else  if($this->userService->isParents($role))
            return $this->userService->indexView('list.parent-list',$this->userService->getParents());
        else
            return view('home');
    }

    /**
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirectToRegisterStudent()
    {
        $q = Input::get ( 'q' );
        $user = \App\User::where('name','LIKE','%'.$q.'%')->orWhere('email','LIKE','%'.$q.'%')->where('role','student')->get();

        $classes = Myclass::query()
            ->bySchool(\Auth::user()->school->id)
            ->pluck('id');

        $sections = Section::with('class')
            ->whereIn('class_id', $classes)
            ->get();

        session([
            'register_role' => 'student',
            'register_sections' => $sections,
        ]);

        return redirect()->route('register');
    }

    /**
     * @param $section_id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function sectionStudents($section_id)
    {
        $students = $this->userService->getSectionStudentsWithSchool($section_id);

        return view('profile.section-students', compact('students'));
    }

    /**
     * @param $section_id
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function promoteSectionStudents(Request $request, $section_id)
    {
        if($this->userService->hasSectionId($section_id))
            return $this->userService->promoteSectionStudentsView(
                $this->userService->getSectionStudentsWithStudentInfo($request, $section_id),
                Myclass::with('sections')->bySchool(\Auth::user()->school_id)->get(),
                $section_id
            );
        else
            return $this->userService->promoteSectionStudentsView([], [], $section_id);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function promoteSectionStudentsPost(Request $request)
    {   
        return $this->userService->promoteSectionStudentsPost($request);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function changePasswordGet()
    {
        return view('profile.change-password');
    }

    /**
     * @param ChangePasswordRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function changePasswordPost(ChangePasswordRequest $request)
    {
        if (Hash::check($request->old_password, Auth::user()->password)) {
            $request->user()->fill([
              'password' => Hash::make($request->new_password),
            ])->save();

            return back()->with('status', __('Saved'));
        }

        return back()->with('error-status', __('Passwords do not match.'));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function impersonateGet()
    {
        if (app('impersonate')->isImpersonating()) {
            Auth::user()->leaveImpersonation();
            return (Auth::user()->role == 'master')?redirect('/masters') : redirect('/home');
        }
        else {
            return view('profile.impersonate', [
                'other_users' => $this->user->where('id', '!=', auth()->id())->get([ 'id', 'name', 'role' ])
            ]);
        }
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function impersonate(ImpersonateUserRequest $request)
    {
        $user = $this->user->find($request->id);
        Auth::user()->impersonate($user);
        return redirect('/home');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CreateUserRequest $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(CreateUserRequest $request)
    {
        DB::transaction(function () use ($request) {
            $password = $request->password;
            $tb = $this->userService->storeStudent($request);
            try {
                // Fire event to store Student information
                if(event(new StudentInfoUpdateRequested($request,$tb->id))){
                    // Fire event to send welcome email
                    event(new UserRegistered($tb, $password));
                } else {
                    throw new \Exeception('Event returned false');
                }
            } catch(\Exception $ex) {
                Log::info('Email failed to send to this address: '.$tb->email.'\n'.$ex->getMessage());
            }
        });

        return back()->with('status', __('Saved'));
    }

    /**
     * @param CreateAdminRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeAdmin(CreateAdminRequest $request)
    {
        $password = $request->password;
        $tb = $this->userService->storeAdmin($request);
        try {
            // Fire event to send welcome email
            // event(new userRegistered($userObject, $plain_password)); // $plain_password(optional)
            event(new UserRegistered($tb, $password));
        } catch(\Exception $ex) {
            Log::info('Email failed to send to this address: '.$tb->email);
        }

        return back()->with('status', __('Saved'));
    }

    /**
     * @param CreateTeacherRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeTeacher(CreateTeacherRequest $request)
    {
        $password = $request->password;
        $tb = $this->userService->storeStaff($request, 'teacher');
        try {
            // Fire event to send welcome email
            event(new UserRegistered($tb, $password));
        } catch(\Exception $ex) {
            Log::info('Email failed to send to this address: '.$tb->email);
        }

        return back()->with('status', __('Saved'));
    }

     /**
     * @param CreateParentRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeParent(CreateParentRequest $request)
    {
        // dd($request->student_id);

        $sid = \App\User::Select('id')->where('name', 'LIKE', '%' . $request->student_id . "%")->first()->toArray();
        $studentId = array_flatten($sid);
        // dd($studentId[0]);

        // if(count($user) > 0)
        //     return view('welcome')->withDetails($user)->withQuery ( $q );
        // else return view ('welcome')->withMessage('No Details found. Try to search again !');
        $request->student_id = $studentId[0];
        $password = $request->password;
        $tb = $this->userService->storeParent($request, 'parent');
        try {
            // Fire event to send welcome email
            event(new UserRegistered($tb, $password));
        } catch(\Exception $ex) {
            Log::info('Email failed to send to this address: '.$tb->email);
        }

        return back()->with('status', __('Saved'));
    }


    public function search(Request $request)
    {
        if (!$request->search == '') {
            if ($request->ajax()) {
                $output = "";
                $products = \App\User::Select('id','name')->where('role', 'student')->where('name', 'LIKE', '%' . $request->search . "%")->get();
                $output = '';
                if (count($products) > 0) {
                    $output = '<ul class="list-group" style="display: block; position: relative; z-index: 1">';
                    foreach ($products as $row) {
                        $output .= '<li class="list-group-item">' . $row->name . '</li>';
                    }
                    $output .= '</ul>';
                } else {
                    $output .= '<li class="list-group-item">' . 'No results' . '</li>';
                }
                return Response($output);
            }
        }
    }


    /**
     * @param CreateAccountantRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeAccountant(CreateAccountantRequest $request)
    {
        $password = $request->password;
        $tb = $this->userService->storeStaff($request, 'accountant');
        try {
            // Fire event to send welcome email
            event(new UserRegistered($tb, $password));
        } catch(\Exception $ex) {
            Log::info('Email failed to send to this address: '.$tb->email);
        }

        return back()->with('status', __('Saved'));
    }

    /**
     * @param CreateLibrarianRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeLibrarian(CreateLibrarianRequest $request)
    {
        $password = $request->password;
        $tb = $this->userService->storeStaff($request, 'librarian');
        try {
            // Fire event to send welcome email
            event(new UserRegistered($tb, $password));
        } catch(\Exception $ex) {
            Log::info('Email failed to send to this address: '.$tb->email);
        }

        return back()->with('status', __('Saved'));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return UserResource
     */
    public function show($user_code)
    {
        $user = $this->userService->getUserByUserCode($user_code);
        $students = $this->userService->getStudentParent($user->student_id);
       
        return view('profile.user', compact('user','students'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $user = $this->user->find($id);
        $studentParents = $this->userService->getStudentParent($user->student_id);
       
        $classes = Myclass::query()
            ->bySchool(\Auth::user()->school_id)
            ->pluck('id')
            ->toArray();

        $sections = Section::query()
            ->whereIn('class_id', $classes)
            ->get();

        $departments = Department::query()
            ->bySchool(\Auth::user()->school_id)
            ->get();

        return view('profile.edit', [
            'user' => $user,
            'students' => $studentParents,
            'sections' => $sections,
            'departments' => $departments,
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateUserRequest $request
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateUserRequest $request)
    {
        $sid = \App\User::Select('id')->where('role','student')->where('name', 'LIKE', '%' . $request->student_id . "%")->get()->toArray();
        $studentId = array_flatten($sid);
        
        $tb = $this->user->find($request->user_id);
        $tb->name = $request->name;
        $tb->student_id = $studentId[0];
        $tb->email = (!empty($request->email)) ? $request->email : '';
        $tb->nationality = (!empty($request->nationality)) ? $request->nationality : '';
        $tb->phone_number = $request->phone_number;
        $tb->address = (!empty($request->address)) ? $request->address : '';
        $tb->about = (!empty($request->about)) ? $request->about : '';
       
       return $this->UpdateData($request,$tb);
    }
    
    /**
     * Update the data in specified resource.
     *
     * @param UpdateUserRequest $request
     * @return \Illuminate\Http\Response
     */
    public function UpdateData(UpdateUserRequest $request,$tb)
    {
        DB::transaction(function () use ($request,$tb) {
            // $tb = $this->user->find($request->user_id);
			if (!empty($request->pic_path)) {
				$tb->pic_path = $request->pic_path;
			}
            if ($request->user_role == 'teacher') {
                $tb->department_id = $request->department_id;
                $tb->section_id = $request->class_teacher_section_id;
            }

            if($tb->save()){
                
                if ($request->user_role == 'student') {
                    
                    try{
                        // Fire event to store Student information
                        event(new StudentInfoUpdateRequested($request,$tb->id));
                    } catch(\Exception $ex) {
                        Log::info('Failed to update Student information, Id: '.$tb->id. 'err:'.$ex->getMessage());
                    }
                }
            }
        });
     
        return back()->with('status', __('Saved'));
    }

    /**
     * Activate admin
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function activateAdmin($id)
    {
        $admin = $this->user->find($id);

        if ($admin->active !== 0) {
            $admin->active = 0;
        } else {
            $admin->active = 1;
        }

        $admin->save();

        return back()->with('status', __('Saved'));
    }

    /**
     * Deactivate admin
     * @param $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function deactivateAdmin($id)
    {
       $admin = $this->user->find($id);

        if ($admin->active !== 1) {
            $admin->active = 1;
        } else {
            $admin->active = 0;
        }

        $admin->save();

        return back()->with('status', __('Saved'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return void
     */
    public function destroy($id)
    {
        // return ($this->user->destroy($id))?response()->json([
      //   'status' => 'success'
      // ]):response()->json([
      //   'status' => 'error'
      // ]);
    }
}
