<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{Photo, Album, User, Source, Tag};
use App\Http\Requests\PhotoRequest;

use App\Jobs\ResizePhoto;
use App\Notifications\PhotoDownloaded;
use App\Mail\PhotoDownloaded as MailPhoto;

use DB, Image, Storage, Str, Mail;


class PhotoController extends Controller
{
    public function create(Album $album)
    { // Formulaire d'ajout de photo à l'album
        abort_if($album->user_id != auth()->id(), 403);

        $data = [
            'title' => $description = 'Ajouter des photos à ' . $album->title,
            'description' => $description,
            'album' => $album,
            'heading' => $album->title,
        ];

        return view('photo.create', $data);
    }

    public function store(PhotoRequest $request, Album $album)
    { // Enregistrement de la photo
        abort_if($album->user_id != auth()->id(), 403);

        DB::beginTransaction();
        try {
            $photo = $album->photos()->create($request->validated());

            $tags = explode(',', $request->tags);
            $tags = collect($tags)->filter(function ($value, $key) {
                return $value != '' && $value != ' ';
            })->all();

            //dd($tags);
            foreach ($tags as $t) {
                $tag = Tag::firstOrCreate(['name' => ucfirst(trim($t))]);
                $photo->tags()->attach($tag->id);
            }

            if ($request->file('photo')->isValid()) {
                $ext = $request->file('photo')->extension();
                $filename = Str::uuid() . '.' . $ext;

                $originalPath = $request->file('photo')->storeAs('photos/' . $photo->album_id, $filename);
                $originalWidth = (int)Image::make($request->file('photo'))->width();
                $originalHeight = (int)Image::make($request->file('photo'))->height();

                $originalSource = $photo->sources()->create([
                    'path' => $originalPath,
                    'url' => Storage::url($originalPath),
                    'size' => Storage::size($originalPath),
                    'width' => $originalWidth,
                    'height' => $originalHeight,
                ]);

                //resize de la photo
                //ResizePhoto::dispatch($originalSource, $photo, $ext);
                DB::afterCommit(fn() => ResizePhoto::dispatch($originalSource, $photo, $ext));

                /*
                $thumbnailImage = Image::make(Storage::get($originalSource->path))->fit(350, 233, function($constraint) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                })->encode($ext, 50);

                $thumbnailPath = 'photos/'.$photo->album_id.' /thumbnails/'.$filename;
                Storage::put($thumbnailPath, $thumbnailImage);

                $photo->thumbnail_path = $thumbnailPath;
                $photo->thumbnail_url = Storage::url($thumbnailPath);

                $photo->save();

                for($i = 2; $i <= 6; $i++) {
                    $width = (int) round($originalSource->width / $i);
                    $height = (int) round($originalSource->height / $i);

                    $img = Image::make(Storage::get($originalSource->path))->fit($width, $height, function($constraint) {
                        $constraint->aspectRatio();
                        $constraint->upsize();
                    })->encode($ext);

                    $filename = Str::uuid().'.'.$ext;

                    $path = 'photos/'.$photo->album_id.'/'.$filename;

                    Storage::put($path, $img);

                    $photo->sources()->create([
                        'path' => $path,
                        'url' => Storage::url($path),
                        'size' => Storage::size($path),
                        'width' => $width,
                        'height' => $height,
                    ]);

                    $photo->active = true;
                    $photo->save();

                }

                */

            }
        } catch (ValidationException $e) {
            db::Rollback();
            dd($e->getErrors());
        }

        DB::commit();

        $success = 'Photo enregistrée';
        $redirect = route('photos.create', [$album->slug]);
        return request()->ajax()
            ? response()->json(['success' => $success, 'redirect' => $redirect])
            : redirect($redirect)->withSuccess($success);
    }

    public function show(Photo $photo)
    {

        $photo->load('tags:name,slug',
            'album.tags:name,slug', 'album.categories:name,slug', 'sources');

        $photo->loadCount(['votes as count_likes' => function($query){
            return $query->where('like', true);
        }, 'votes as count_dislikes' => function($query){
            return $query->where('dislike', true);
        }]);

        $tags = collect($photo->tags)->merge(collect($photo->album->tags))->unique();

        $categories = $photo->album->categories;

        $data = [
            'title' => $photo->title.' - '.config('app.name'),
            'description' => $photo->title . '. ' . $tags->implode('name', ', ') . ' '
                . $categories->implode('name', ', '),
            'photo' => $photo,
            'tags' => $tags,
            'categories' => $categories,
            'heading'=>$photo->title,
        ];
        return view('photo.show', $data);
    }

    public function readAll() {
        auth()->user()->unreadNotifications->markAsRead();
        return back();
    }

    public function vote(Photo $photo, string $vote, string $token)
    {
        abort_if($token !== session()->token(), 403);

        abort_unless(in_array($vote, ['like', 'dislike']), 403);

        $photo->load('votes');

        if($photo->votes->where('session_token', session()->token())->count()){
            $error = 'Vous avez déja voté.';
            return request()->ajax()
                ? response()->json(['error'=>$error])
                : back()->withError($error);
        }

        $like = $vote == 'like';
        $dislike = $vote == 'dislike';

        $success = $like ? 'Vous aimez cette photo.' : 'Vous n\'aimez pas cette photo.';

        $vote = $photo->votes()->create([
            'like' => $like,
            'dislike' => $dislike,
            'session_token' => session()->token(),
        ]);

        $redirect = route('photos.show', [$photo->slug]);

        return request()->ajax()
            ? response()->json(['success' => $success, 'redirect' => $redirect])
            : back()->withSuccess($success);
    }

    public function download() {
        request()->validate([
            'source'=>['required', 'exists:sources,id'],
        ]);
        $source = Source::findOrFail(request('source'));
        $source->load('photo.album.user');
        abort_if(! $source->photo->active, 403);

        if(auth()->id() !== $source->photo->album->user_id){
            $source->photo->album->user->notify(new PhotoDownloaded($source, $source->photo, auth()->user()));

            Mail::to(auth()->user())->send(new MailPhoto($source, auth()->user()));
        }

        $download = $source->photo->downloads()->create([
            'user_id' => auth()->id(),
            'width' => $source->width,
            'height' => $source->height,
            'size' => Storage::size($source->path),
            'ip_address' => request()->ip(),
        ]);


        return Storage::download($source->path);

    }

    public function destroy(Photo $photo)
    {
        $photo->load('sources', 'album');

        abort_if($photo->album->user_id !== auth()->id(), 403);

        DB::beginTransaction();

        try{
            DB::afterCommit(function() use ($photo){
                foreach($photo->sources as $source){
                    Storage::delete($source->path);
                }
                Storage::delete($photo->thumbnail_path);
            });

            $photo->delete();
        }
        catch(ValidationException $e){
            DB::rollBack();
            dd($e->getErrors());
        }

        DB::commit();

        $success = 'Photo supprimée.';

        return request()->ajax()
            ? response()->json(['success' => $success, 'redirect' => url()->previous()])
            : back()->withSuccess($success);
    }

}

