<?php

namespace App\Http\Controllers;

use App\Models\Curso;
use App\Models\Disciplina;
use App\Services\Diff;
use App\Services\Pdf;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Uspdev\UspTheme\Facades\UspTheme;

class DisciplinaController extends Controller
{
    public function __construct()
    {
        $this->middleware(function (Request $request, Closure $next) {
            UspTheme::activeUrl('disciplinas');
            return $next($request);
        });
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Disciplina::class);

        // qual visão o usuario quer: docente, cg, chefe, etc
        // persistido na session
        if ($request->visao) {
            $visao = $request->visao;
            $request->session()->put('disciplinas.visao', $visao);
        } else {
            $visao = $request->session()->get('disciplinas.visao', 'docente');
        }

        $user = Auth::user();

        switch ($visao) {
            case 'cg':
                if (!Gate::allows('disciplina-cg')) {
                    $request->session()->put('disciplinas.visao', 'docente');
                    return redirect()->action([self::class, 'index']);
                }
                $discs = Disciplina::listarDisciplinas();
                $request->session()->put('disciplinas.visao', 'cg');
                break;

            case 'departamento':
                if (!Gate::allows('disciplina-chefe')) {
                    $request->session()->put('disciplinas.visao', 'docente');
                    return redirect()->action([self::class, 'index']);
                }
                $discs = collect();
                foreach ($user->prefixos() as $prefixo) {
                    $discs = $discs->merge(Disciplina::listarDisciplinasPorPrefixo($prefixo));
                }
                $request->session()->put('disciplinas.visao', 'departamento');
                break;
            default:
                $discs = Disciplina::listarDisciplinasPorResponsavel($user->codpes);
        }

        $discs = $discs->sortBy('coddis');

        return view('disciplinas.index', compact('discs', 'visao'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  String  $coddis
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $coddis)
    {
        $this->authorize('viewAny', Disciplina::class);

        $versao = $request->v ?: null; // vai desarivar versões anteriores?
        $coddis = strtoupper($coddis);
        $cursos = [];

        $disc = Disciplina::primeiroOuNovo($coddis);
        $dr = $disc->dr;

        return view('disciplinas.show', compact('dr', 'coddis', 'disc'));

        // https://github.com/arnab/jQuery.PrettyTextDiff?tab=readme-ov-file
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param string $coddis
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $coddis)
    {
        $this->authorize('user');
        $disc = Disciplina::primeiroOuNovo(strtoupper($coddis));
        $this->authorize('update', $disc);

        $disc->mesclarResponsaveisReplicado();

        // disciplina-replicado -> cursos da unidade que aparece a disciplina
        $cursos = [];
        foreach ($disc->dr['cursos'] as $curso_dr) {
            if (stripos(config('replicado.codundclgs'), $curso_dr['codclg']) !== false) {
                // é curso da unidade
                $curso = Curso::where('codcur', $curso_dr['codcur'])->first();
                if (!$curso) {
                    $curso = new Curso();
                    $curso->codcur = $curso_dr['codcur'];
                    $curso->dr = $curso_dr;
                }
                $cursos[] = $curso;
            }
        }
        $disc->cursos = $cursos;

        return view('disciplinas.edit', compact('disc'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string $coddis
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $coddis)
    {
        $request->validate([]);
        $disc = Disciplina::primeiroOuNovo($coddis);
        $this->authorize('update', $disc);

        // para aprovação, finaliza a edição do pdf
        if ($request->estado == 'Em aprovação') {
            $disc->atualizarEstado('Em aprovação');
            $disc->save();
            Disciplina::renovarCacheAfterResponse();
            return redirect()
                ->route('disciplinas.preview-html', $disc->coddis)
                ->with('alert-success', 'Disciplina enviada para aprovação com sucesso!');
        }

        $disc->atualizarEstado('Em edição');

        if ($add = $request->codpes_add) {
            $disc->adicionarResponsavel($add);
        }

        if ($rem = $request->codpes_rem) {
            $disc->removerResponsavel($rem);
        }

        $disc->fill($request->all());
        if ($disc->isDirty()) {
            $disc->save();
            Disciplina::renovarCacheAfterResponse();
        }
        if ($request->submit == 'preview') {
            $disc->diffs = Diff::computar($disc);
            $disc = Pdf::quebrarTextoLongo($disc);

            $pdf = Pdf::gerarPdfAlteracaoDisciplina($disc);

            $disc->refresh();
            unset($disc->diffs);
            $disc->pdf = $pdf;
            $disc->save();

            Disciplina::renovarCacheAfterResponse();
            return redirect()->route('disciplinas.preview', $disc->coddis);
        }

        $request->session()->flash('alert-info', 'Dados salvo com sucesso!');

        if ($request->submit == 'preview-html') {
            return redirect()->route('disciplinas.preview-html', $disc->coddis);
        }
        if ($request->next) {
            return redirect()->to($request->next);
        }
        return redirect()->route('disciplinas.edit', $disc->coddis);
    }

    /**
     * Realiza o preview do PDF da disciplina em alteração/criação
     *
     * @param String $disc
     */
    public function preview($coddis)
    {
        $disc = Disciplina::where('coddis', $coddis)->first();

        $url = Storage::temporaryUrl('disciplinas/disciplina-' . $coddis . '.pdf', now()->addMinutes(10), ['ResponseContentDisposition' => 'attachment; filename=file2.pdf']);

        return view('disciplinas.preview', compact('disc', 'url'));
    }

    /**
     * Realiza o preview em HTML da disciplina em alteração/criação
     * 
     * @param String $coddis
     */
    public function previewHtml($coddis)
    {
        $this->authorize('viewAny', Disciplina::class);
        $disc = Disciplina::primeiroOuNovo(strtoupper($coddis));

        $disc->mesclarResponsaveisReplicado();

        // disciplina-replicado -> cursos da unidade que aparece a disciplina
        $cursos = [];
        foreach ($disc->dr['cursos'] as $curso_dr) {
            if (stripos(config('replicado.codundclgs'), $curso_dr['codclg']) !== false) {
                // é curso da unidade
                $curso = Curso::where('codcur', $curso_dr['codcur'])->first();
                if (!$curso) {
                    $curso = new Curso();
                    $curso->codcur = $curso_dr['codcur'];
                    $curso->dr = $curso_dr;
                }
                $cursos[] = $curso;
            }
        }
        $disc->cursos = $cursos;

        return view('disciplinas.preview-html', compact('disc'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }

    public function ajuda()
    {

        $md = file_get_contents(base_path('docs/disciplinas.md'));

        return view('disciplinas.ajuda', compact('md'));
    }
}
